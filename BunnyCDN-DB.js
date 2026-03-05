class BunnySecureDBException extends Error {
    constructor(message) {
        super(message);
        this.name = 'BunnySecureDBException';
    }
}

class BunnySecureDB {
    static instance = null;

    constructor(config = {}) {
        const endpoint = String(config.endpoint ?? config.url ?? config.connection_url ?? '');
        let token = String(config.token ?? config.access_token ?? '');

        if (!endpoint) {
            throw new BunnySecureDBException("Missing config. Provide 'endpoint' (or 'url' / 'connection_url').");
        }

        this.endpoint = this.normalizeEndpoint(endpoint);

        if (!token) {
            token = this.extractTokenFromUrl(endpoint);
        }

        if (!token) {
            throw new BunnySecureDBException("Missing token. Provide 'token' (or 'access_token'), or include token/authToken in URL query.");
        }

        this.token = token;
        this.timeout = Math.max(1, Number(config.timeout ?? 15));

        this.reset();
    }

    static getInstance(config = null) {
        if (!BunnySecureDB.instance) {
            if (!config || typeof config !== 'object') {
                throw new BunnySecureDBException('Configuration must be provided on first getInstance() call.');
            }
            BunnySecureDB.instance = new BunnySecureDB(config);
        }
        return BunnySecureDB.instance;
    }

    static resetInstance() {
        BunnySecureDB.instance = null;
    }

    normalizeEndpoint(endpoint) {
        const value = String(endpoint).trim();
        if (!value) {
            throw new BunnySecureDBException('Empty endpoint/url provided.');
        }

        if (/^libsql:\/\//i.test(value)) {
            try {
                const parsed = new URL(value);
                const host = parsed.host;
                if (!host) {
                    throw new BunnySecureDBException('Invalid libsql URL. Expected: libsql://<DB-ID>.lite.bunnydb.net/');
                }
                return `https://${host}/v2/pipeline`;
            } catch {
                throw new BunnySecureDBException('Invalid libsql URL. Expected: libsql://<DB-ID>.lite.bunnydb.net/');
            }
        }

        const stripped = value.replace(/\/+$/, '');
        return /\/v2\/pipeline$/i.test(stripped) ? stripped : `${stripped}/v2/pipeline`;
    }

    extractTokenFromUrl(url) {
        try {
            const parsed = new URL(String(url));
            return String(parsed.searchParams.get('token') ?? parsed.searchParams.get('authToken') ?? '').trim();
        } catch {
            return '';
        }
    }

    async select(sql, params = []) {
        const res = await this.executePipeline([
            this.buildExecuteRequest(sql, params),
            { type: 'close' }
        ]);

        return this.extractRowsFromPipeline(res, 0);
    }

    async q(sql, params = []) {
        return this.query(sql, params);
    }

    async query(sql, params = []) {
        const type = this.detectQueryType(sql);

        const res = await this.executePipeline([
            this.buildExecuteRequest(sql, params),
            { type: 'close' }
        ]);

        const result = this.extractResultFromPipeline(res, 0);

        if (type === 'SELECT' || type === 'PRAGMA') {
            return this.decodeRows(result);
        }
        if (type === 'INSERT') {
            return result.last_insert_rowid !== undefined ? Number(result.last_insert_rowid) : null;
        }
        if (type === 'UPDATE' || type === 'DELETE') {
            return Number(result.affected_row_count ?? 0);
        }
        return true;
    }

    from(table, columns = ['*']) {
        this.reset();
        this.fluentTable = this.validateTableName(table);
        this.fluentOperation = 'select';
        this.fluentColumns = Array.isArray(columns) && columns.length ? columns : ['*'];
        return this;
    }

    where(conditions = {}) {
        this.fluentWhere = conditions && typeof conditions === 'object' ? conditions : {};
        
        // Auto-execute DELETE when where() is called in delete mode
        if (this.fluentOperation === 'delete' && this.fluentTable && Object.keys(this.fluentWhere).length) {
            return this.executeDelete();
        }
        
        return this;
    }

    async execute() {
        if (this.fluentOperation === 'delete' && this.fluentTable) {
            return this.executeDelete();
        }
        throw new BunnySecureDBException('execute() can only be used after delete() with where()');
    }

    orderBy(column, direction = 'ASC') {
        if (this.fluentOperation !== 'select') {
            throw new BunnySecureDBException('orderBy() can only be used with SELECT operations.');
        }

        const dir = String(direction).toUpperCase();
        if (dir !== 'ASC' && dir !== 'DESC') {
            throw new BunnySecureDBException('Invalid order direction. Use ASC or DESC.');
        }

        const col = this.validateColumnName(column);
        this.fluentOrderBy = `${this.quoteIdent(col)} ${dir}`;
        return this;
    }

    limit(count) {
        if (this.fluentOperation !== 'select') {
            throw new BunnySecureDBException('limit() can only be used with SELECT operations.');
        }

        this.fluentLimit = Math.max(0, Number(count) || 0);
        return this;
    }

    async get() {
        if (this.fluentOperation !== 'select' || !this.fluentTable) {
            throw new BunnySecureDBException("Use from('table')->get()");
        }

        const cols = this.fluentColumns;
        let colSql = '*';

        if (!(Array.isArray(cols) && cols.length === 1 && cols[0] === '*')) {
            colSql = cols.map((c) => {
                if (c === '*') return '*';
                return this.quoteIdent(this.validateColumnName(String(c)));
            }).join(', ');
        }

        let sql = `SELECT ${colSql} FROM ${this.quoteIdent(this.fluentTable)}`;
        let args = [];

        if (this.fluentWhere && Object.keys(this.fluentWhere).length) {
            const whereCols = Object.keys(this.fluentWhere);
            const whereSql = whereCols.map((col) => `${this.quoteIdent(this.validateColumnName(col))} = ?`).join(' AND ');
            sql += ` WHERE ${whereSql}`;
            args = whereCols.map((col) => this.fluentWhere[col]);
        }

        if (this.fluentOrderBy) {
            sql += ` ORDER BY ${this.fluentOrderBy}`;
        }

        if (this.fluentLimit > 0) {
            sql += ` LIMIT ${this.fluentLimit}`;
        }

        const res = await this.executePipeline([
            this.buildExecuteRequest(sql, args),
            { type: 'close' }
        ]);

        const rows = this.extractRowsFromPipeline(res, 0);
        this.reset();
        return rows;
    }

    insert(table, data = null) {
        if (data && typeof data === 'object' && Object.keys(data).length > 0) {
            return this.insertNow(table, data);
        }

        this.reset();
        this.fluentTable = this.validateTableName(table);
        this.fluentOperation = 'insert';
        return this;
    }

    async insertNow(table, data) {
        const validTable = this.validateTableName(table);
        const cols = Object.keys(data);

        const colList = cols.map((c) => this.quoteIdent(this.validateColumnName(c))).join(', ');
        const placeholders = cols.map(() => '?').join(', ');
        const sql = `INSERT INTO ${this.quoteIdent(validTable)} (${colList}) VALUES (${placeholders})`;

        const res = await this.executePipeline([
            this.buildExecuteRequest(sql, cols.map((c) => data[c])),
            { type: 'close' }
        ]);

        const result = this.extractResultFromPipeline(res, 0);
        return result.last_insert_rowid !== undefined ? Number(result.last_insert_rowid) : 0;
    }

    async row(data) {
        if (this.fluentOperation !== 'insert' || !this.fluentTable) {
            throw new BunnySecureDBException("Use insert('table')->row({...})");
        }

        if (!data || typeof data !== 'object' || !Object.keys(data).length) {
            throw new BunnySecureDBException('No data provided for insert row().');
        }

        const insertedId = await this.insertNow(this.fluentTable, data);
        this.reset();
        return insertedId;
    }

    insertMultiple(table, rows = null, batchSize = 1000) {
        if (Array.isArray(rows) && rows.length > 0) {
            return this.executeInsertMultiple(this.validateTableName(table), rows, batchSize);
        }

        this.reset();
        this.fluentTable = this.validateTableName(table);
        this.fluentOperation = 'insertMultiple';
        this.fluentBatchSize = Math.max(1, Number(batchSize) || 1000);
        return this;
    }

    batch(size) {
        this.fluentBatchSize = Math.max(1, Number(size) || 1);
        return this;
    }

    async rows(rows) {
        if (this.fluentOperation !== 'insertMultiple' || !this.fluentTable) {
            throw new BunnySecureDBException("Use insertMultiple('table')->rows([...])");
        }

        const total = await this.executeInsertMultiple(this.fluentTable, rows, this.fluentBatchSize);
        this.reset();
        return total;
    }

    update(table, data = null, where = null) {
        if (data && where && typeof data === 'object' && typeof where === 'object' && Object.keys(data).length && Object.keys(where).length) {
            return this.updateNow(table, data, where);
        }

        this.reset();
        this.fluentTable = this.validateTableName(table);
        this.fluentOperation = 'update';
        return this;
    }

    async updateNow(table, data, where) {
        const validTable = this.validateTableName(table);
        const setCols = Object.keys(data);
        const whereCols = Object.keys(where);

        const setSql = setCols.map((c) => `${this.quoteIdent(this.validateColumnName(c))} = ?`).join(', ');
        const whereSql = whereCols.map((c) => `${this.quoteIdent(this.validateColumnName(c))} = ?`).join(' AND ');

        const sql = `UPDATE ${this.quoteIdent(validTable)} SET ${setSql} WHERE ${whereSql}`;
        const args = [...setCols.map((c) => data[c]), ...whereCols.map((c) => where[c])];

        const res = await this.executePipeline([
            this.buildExecuteRequest(sql, args),
            { type: 'close' }
        ]);

        const result = this.extractResultFromPipeline(res, 0);
        return Number(result.affected_row_count ?? 0);
    }

    async change(data) {
        if (this.fluentOperation !== 'update' || !this.fluentTable) {
            throw new BunnySecureDBException("Use update('table')->where({...})->change({...})");
        }

        if (!this.fluentWhere || !Object.keys(this.fluentWhere).length) {
            throw new BunnySecureDBException('No WHERE specified. Use where({...}) first.');
        }

        if (!data || typeof data !== 'object' || !Object.keys(data).length) {
            throw new BunnySecureDBException('No data provided for update.');
        }

        const affected = await this.updateNow(this.fluentTable, data, this.fluentWhere);
        this.reset();
        return affected;
    }

    delete(table, where = null) {
        if (where && typeof where === 'object' && Object.keys(where).length) {
            return this.deleteNow(table, where);
        }

        this.reset();
        this.fluentTable = this.validateTableName(table);
        this.fluentOperation = 'delete';
        return this;
    }

    async deleteNow(table, where) {
        const validTable = this.validateTableName(table);
        const whereCols = Object.keys(where);

        const whereSql = whereCols.map((c) => `${this.quoteIdent(this.validateColumnName(c))} = ?`).join(' AND ');
        const sql = `DELETE FROM ${this.quoteIdent(validTable)} WHERE ${whereSql}`;

        const res = await this.executePipeline([
            this.buildExecuteRequest(sql, whereCols.map((c) => where[c])),
            { type: 'close' }
        ]);

        const result = this.extractResultFromPipeline(res, 0);
        return Number(result.affected_row_count ?? 0);
    }

    async createTable(table, columns, ifNotExists = true) {
        if (!columns || typeof columns !== 'object' || !Object.keys(columns).length) {
            throw new BunnySecureDBException('No columns provided for table creation.');
        }

        const validTable = this.validateTableName(table);
        const ifNotExistsSql = ifNotExists ? ' IF NOT EXISTS' : '';

        const columnDefinitions = [];

        Object.entries(columns).forEach(([name, definition]) => {
            const n = String(name);
            const d = String(definition ?? '').trim();

            if (!d) {
                columnDefinitions.push(n);
                return;
            }

            if (/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(n)) {
                columnDefinitions.push(`${this.quoteIdent(n)} ${d}`);
            } else {
                columnDefinitions.push(n);
            }
        });

        const sql = `CREATE TABLE${ifNotExistsSql} ${this.quoteIdent(validTable)} (${columnDefinitions.join(', ')})`;

        await this.executePipeline([
            this.buildExecuteRequest(sql, []),
            { type: 'close' }
        ]);

        this.reset();
        return true;
    }

    async deleteTable(table, ifExists = true) {
        const validTable = this.validateTableName(table);
        const ifExistsSql = ifExists ? ' IF EXISTS' : '';
        const sql = `DROP TABLE${ifExistsSql} ${this.quoteIdent(validTable)}`;

        await this.executePipeline([
            this.buildExecuteRequest(sql, []),
            { type: 'close' }
        ]);

        this.reset();
        return true;
    }

    async dropTable(table, ifExists = true) {
        return this.deleteTable(table, ifExists);
    }

    async executeInsertMultiple(table, rows, batchSize) {
        if (!Array.isArray(rows) || rows.length === 0) {
            throw new BunnySecureDBException('No rows provided for bulk insert.');
        }

        const columns = Object.keys(rows[0]);
        const signature = JSON.stringify(columns);

        rows.forEach((row) => {
            if (JSON.stringify(Object.keys(row)) !== signature) {
                throw new BunnySecureDBException('All rows must have the same keys in the same order.');
            }
        });

        const tableQ = this.quoteIdent(table);
        const colList = columns.map((c) => this.quoteIdent(this.validateColumnName(c))).join(', ');
        const placeholders = columns.map(() => '?').join(', ');
        const insertSql = `INSERT INTO ${tableQ} (${colList}) VALUES (${placeholders})`;

        let total = 0;
        const safeBatchSize = Math.max(1, Number(batchSize) || 1);

        for (let i = 0; i < rows.length; i += safeBatchSize) {
            const batch = rows.slice(i, i + safeBatchSize);
            const requests = [];

            requests.push({ type: 'execute', stmt: { sql: 'BEGIN' } });
            batch.forEach((row) => {
                requests.push(this.buildExecuteRequest(insertSql, columns.map((c) => row[c])));
            });
            requests.push({ type: 'execute', stmt: { sql: 'COMMIT' } });
            requests.push({ type: 'close' });

            const res = await this.executePipeline(requests);

            for (let r = 1; r <= batch.length; r += 1) {
                const result = this.extractResultFromPipeline(res, r);
                total += Number(result.affected_row_count ?? 0);
            }
        }

        return total;
    }

    async executeDelete() {
        if (!this.fluentTable) {
            throw new BunnySecureDBException("No table specified. Use delete('table') first.");
        }

        if (!this.fluentWhere || !Object.keys(this.fluentWhere).length) {
            throw new BunnySecureDBException('No WHERE specified. Use where({...}) first.');
        }

        const count = await this.deleteNow(this.fluentTable, this.fluentWhere);
        this.reset();
        return count;
    }

    resetState() {
        this.reset();
        return this;
    }

    async executePipeline(requests) {
        const payload = JSON.stringify({ requests });

        let rawResponse;
        let statusCode = 0;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.timeout * 1000);

            const response = await fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.token}`,
                    'Content-Type': 'application/json'
                },
                body: payload,
                signal: controller.signal
            });

            clearTimeout(timeoutId);
            statusCode = response.status;
            rawResponse = await response.text();
        } catch (error) {
            const message = error && error.message ? error.message : 'Unknown request failure';
            throw new BunnySecureDBException(`Network error: ${message}`);
        }

        let json;
        try {
            json = JSON.parse(rawResponse);
        } catch {
            throw new BunnySecureDBException(`Invalid JSON response (HTTP ${statusCode}): ${rawResponse}`);
        }

        if (Array.isArray(json.results)) {
            json.results.forEach((r, idx) => {
                if ((r?.type ?? '') === 'error') {
                    const msg = r?.error?.message ?? 'Unknown pipeline error';
                    throw new BunnySecureDBException(`Pipeline error at results[${idx}]: ${msg}`);
                }
            });
        }

        if (statusCode >= 400) {
            throw new BunnySecureDBException(`HTTP ${statusCode} error: ${rawResponse}`);
        }

        return json;
    }



    buildExecuteRequest(sql, params) {
        if (this.isAssoc(params)) {
            const namedArgs = Object.keys(params).map((name) => ({
                name: String(name),
                value: this.encodeValue(params[name])
            }));

            return {
                type: 'execute',
                stmt: {
                    sql,
                    named_args: namedArgs
                }
            };
        }

        const args = (Array.isArray(params) ? params : []).map((value) => this.encodeValue(value));

        return {
            type: 'execute',
            stmt: {
                sql,
                args
            }
        };
    }

    extractResultFromPipeline(pipelineResponse, index) {
        const results = pipelineResponse?.results;
        if (!Array.isArray(results) || results[index] === undefined) {
            throw new BunnySecureDBException(`Missing pipeline result at index ${index}.`);
        }

        const r = results[index];

        if ((r?.type ?? '') !== 'ok') {
            const msg = r?.error?.message ?? 'Unknown result error';
            throw new BunnySecureDBException(`Non-ok result at index ${index}: ${msg}`);
        }

        const response = r.response ?? {};
        if ((response?.type ?? '') !== 'execute') {
            return response;
        }

        return response.result ?? {};
    }

    extractRowsFromPipeline(pipelineResponse, index) {
        const result = this.extractResultFromPipeline(pipelineResponse, index);
        return this.decodeRows(result);
    }

    decodeRows(result) {
        const cols = Array.isArray(result?.cols) ? result.cols : [];
        const rows = Array.isArray(result?.rows) ? result.rows : [];

        const colNames = cols.map((c, i) => String(c?.name ?? `col_${i}`));

        return rows.map((row) => {
            const assoc = {};
            row.forEach((cell, i) => {
                const name = colNames[i] ?? `col_${i}`;
                assoc[name] = this.decodeValue(cell && typeof cell === 'object' ? cell : { type: 'null' });
            });
            return assoc;
        });
    }

    encodeValue(v) {
        if (v === null || v === undefined) {
            return { type: 'null' };
        }

        if (Number.isInteger(v)) {
            return { type: 'integer', value: String(v) };
        }

        if (typeof v === 'number') {
            return { type: 'float', value: String(v) };
        }

        if (typeof v === 'boolean') {
            return { type: 'integer', value: v ? '1' : '0' };
        }

        if (typeof v === 'string') {
            return { type: 'text', value: v };
        }

        if (typeof v === 'object') {
            return { type: 'text', value: JSON.stringify(v) };
        }

        return { type: 'text', value: String(v) };
    }

    decodeValue(cell) {
        const type = cell?.type ?? 'null';
        const value = cell?.value ?? null;

        if (type === 'null') return null;
        if (type === 'integer') return typeof value === 'string' ? Number.parseInt(value, 10) : 0;
        if (type === 'float') return typeof value === 'string' ? Number.parseFloat(value) : 0;
        if (type === 'text') return value;
        if (type === 'blob') {
            if (typeof value !== 'string') return null;
            try {
                return atob(value);
            } catch {
                return null;
            }
        }

        return value;
    }

    detectQueryType(sql) {
        let source = String(sql ?? '').trim();
        source = source.replace(/^\/\*[\s\S]*?\*\/\s*/, '');
        source = source.replace(/^--.*$/gm, '');
        source = source.trim();

        const first = (source.split(/\s+/)[0] ?? '').toUpperCase();
        return first || 'OTHER';
    }

    reset() {
        this.fluentTable = '';
        this.fluentWhere = {};
        this.fluentBatchSize = 1000;
        this.fluentOperation = '';
        this.fluentColumns = ['*'];
        this.fluentOrderBy = '';
        this.fluentLimit = 0;
    }

    isAssoc(value) {
        return value && typeof value === 'object' && !Array.isArray(value);
    }

    validateTableName(table) {
        const t = String(table ?? '').trim().replace(/^["`\s]+|["`\s]+$/g, '');
        if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(t)) {
            throw new BunnySecureDBException(`Invalid table name: '${t}'`);
        }
        return t;
    }

    validateColumnName(col) {
        const c = String(col ?? '').trim().replace(/^["`\s]+|["`\s]+$/g, '');
        if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(c)) {
            throw new BunnySecureDBException(`Invalid column name: '${c}'`);
        }
        return c;
    }

    quoteIdent(ident) {
        const value = String(ident).replace(/"/g, '""');
        return `"${value}"`;
    }
}

if (typeof window !== 'undefined') {
    window.BunnySecureDB = BunnySecureDB;
    window.BunnySecureDBException = BunnySecureDBException;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { BunnySecureDB, BunnySecureDBException };
}
