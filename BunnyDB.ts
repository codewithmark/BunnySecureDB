import { createClient } from "@libsql/client/web";

type DBValue = string | number | boolean | null;
type DBRow = Record<string, DBValue>;

export class BunnyDB {

  private db: ReturnType<typeof createClient>;
  private table = "";
  private whereData: DBRow = {};
  private op = "";

  constructor(config: { url: string; authToken: string }) {
    this.db = createClient(config);
  }

  private reset() {
    this.table = "";
    this.whereData = {};
    this.op = "";
  }

  private quote(name: string) {
    if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(name)) {
      throw new Error(`Invalid identifier: ${name}`);
    }
    return `"${name}"`;
  }

  async select<T = any>(sql: string, args: DBValue[] = []): Promise<T[]> {
    const r = await this.db.execute({ sql, args });
    return r.rows as T[];
  }

  async one<T = any>(sql: string, args: DBValue[] = []): Promise<T | null> {
    const r = await this.db.execute({ sql, args });
    return (r.rows[0] as T) ?? null;
  }

  async insert(table: string, data: DBRow) {

    const cols = Object.keys(data);

    const sql =
      `INSERT INTO ${this.quote(table)} (` +
      cols.map(c => this.quote(c)).join(", ") +
      `) VALUES (` +
      cols.map(() => "?").join(", ") +
      `)`;

    const args = cols.map(c => data[c]);

    const r = await this.db.execute({ sql, args });

    return r.lastInsertRowid ?? null;
  }

  async insertMany(table: string, rows: DBRow[], chunk = 250) {

    if (!rows.length) return 0;

    const cols = Object.keys(rows[0]);
    const tableSql = this.quote(table);
    const colSql = cols.map(c => this.quote(c)).join(", ");

    let inserted = 0;

    for (let i = 0; i < rows.length; i += chunk) {

      const group = rows.slice(i, i + chunk);

      const values = group
        .map(() => `(${cols.map(() => "?").join(", ")})`)
        .join(", ");

      const args = group.flatMap(row =>
        cols.map(c => row[c])
      );

      const sql = `INSERT INTO ${tableSql} (${colSql}) VALUES ${values}`;

      const r = await this.db.execute({ sql, args });

      inserted += Number(r.rowsAffected ?? 0);
    }

    return inserted;
  }

  update(table: string) {
    this.reset();
    this.table = table;
    this.op = "update";
    return this;
  }

  delete(table: string) {
    this.reset();
    this.table = table;
    this.op = "delete";
    return this;
  }

  where(where: DBRow) {

    this.whereData = where;

    if (this.op === "delete") {
      return this.execDelete();
    }

    return this;
  }

  async change(data: DBRow) {

    if (this.op !== "update") {
      throw new Error("change() requires update()");
    }

    const setCols = Object.keys(data);
    const whereCols = Object.keys(this.whereData);

    const sql =
      `UPDATE ${this.quote(this.table)} SET ` +
      setCols.map(c => `${this.quote(c)}=?`).join(", ") +
      ` WHERE ` +
      whereCols.map(c => `${this.quote(c)}=?`).join(" AND ");

    const args = [
      ...setCols.map(c => data[c]),
      ...whereCols.map(c => this.whereData[c])
    ];

    const r = await this.db.execute({ sql, args });

    this.reset();

    return Number(r.rowsAffected ?? 0);
  }

  private async execDelete() {

    const cols = Object.keys(this.whereData);

    const sql =
      `DELETE FROM ${this.quote(this.table)} WHERE ` +
      cols.map(c => `${this.quote(c)}=?`).join(" AND ");

    const args = cols.map(c => this.whereData[c]);

    const r = await this.db.execute({ sql, args });

    this.reset();

    return Number(r.rowsAffected ?? 0);
  }
}