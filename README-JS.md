# 🐰 BunnySecureDB (JavaScript)

> A SecureDB-style JavaScript wrapper for Bunny.net Database  
> Simple. Fluent. SQLite-compatible. Built for web developers.

BunnySecureDB is a lightweight JavaScript class that connects to **[https://bunny.net](https://bunny.net?ref=9zzlt7jfxy) Database (libSQL / SQLite compatible)** using the HTTP SQL API.

No heavy ORM.  
No complex setup.  
Just clean CRUD.

---

## 🚀 Features

- ✅ CREATE TABLE
- ✅ DROP TABLE
- ✅ SELECT
- ✅ INSERT (single + bulk)
- ✅ UPDATE
- ✅ DELETE
- ✅ Fluent chaining
- ✅ Safe parameter binding
- ✅ Batch insert with transactions
- ✅ SQLite-compatible
- ✅ No external dependencies
- ✅ Promise-based async/await support

---

## 📦 Requirements

- JavaScript ES2020+
- Browser or Node.js with fetch API support
- Bunny.net Database instance
- Bunny Database access token

---

## 🔧 Installation

### 1️⃣ Download the class

Copy `BunnyCDN-DB.js` into your project.

### 2️⃣ Include it

#### In browser:
```html
<script src="BunnyCDN-DB.js"></script>
```

#### In Node.js:
```javascript
const { BunnySecureDB } = require('./BunnyCDN-DB.js');
// or with ES modules
import { BunnySecureDB } from './BunnyCDN-DB.js';
```

---

## 🔐 Setup & Configuration

```javascript
const db = BunnySecureDB.getInstance({
    endpoint: 'libsql://xxx.lite.bunnydb.net/',  
    token: 'YOUR_ACCESS_TOKEN'
});
```

You can find:

- `endpoint` → [Bunny Database dashboard](https://bunny.net?ref=9zzlt7jfxy)
- `token` → Access token section

### Alternative Configuration Options

```javascript
// Using URL/connection_url alias
const db = BunnySecureDB.getInstance({
    url: 'libsql://xxx.lite.bunnydb.net/',  
    access_token: 'YOUR_ACCESS_TOKEN'
});

// Include token in URL
const db = BunnySecureDB.getInstance({
    endpoint: 'libsql://xxx.lite.bunnydb.net/?token=YOUR_ACCESS_TOKEN'
});

// Custom timeout (default: 15 seconds)
const db = BunnySecureDB.getInstance({
    endpoint: 'libsql://xxx.lite.bunnydb.net/',  
    token: 'YOUR_ACCESS_TOKEN',
    timeout: 30
});
```

---

## 🏗 CREATE TABLE

### Create Table

```javascript
const success = await db.createTable('users', {
    id: 'INTEGER PRIMARY KEY AUTOINCREMENT',
    name: 'TEXT NOT NULL',
    email: 'TEXT UNIQUE',
    created_at: 'DATETIME DEFAULT CURRENT_TIMESTAMP'
});
```

Pass `false` as third argument to create without "IF NOT EXISTS" check:

```javascript
const success = await db.createTable('users', {...}, false); // Create or fail
```

Returns `true` on success.

---

## 🗑 DROP TABLE

### Drop Table

```javascript
const success = await db.dropTable('users');
```

Returns `true` on success.

---

## 📖 SELECT

### Simple Select

```javascript
const rows = await db.select('SELECT * FROM users');
const rows = await db.select('SELECT id, name FROM users WHERE id = ?', [1]);
```

### Fluent Select

```javascript
const users = await db.from('users')
    .where({ id: 1 })
    .orderBy('name', 'ASC')
    .limit(10)
    .get();
```

### Query (SELECT, PRAGMA, etc.)

```javascript
const rows = await db.query('SELECT * FROM users');
const rows = await db.q('SELECT * FROM users WHERE id = ?', [1]); // Alias
```

---

## ➕ INSERT

### Single Insert

```javascript
const insertedId = await db.insert('users', {
    name: 'John Doe',
    email: 'john@example.com'
});
```

### Fluent Insert (Single Row)

```javascript
const insertedId = await db.insert('users')
    .row({
        name: 'Jane Doe',
        email: 'jane@example.com'
    });
```

### Bulk Insert

```javascript
const insertedIds = await db.insertMultiple('users', [
    { name: 'John', email: 'john@example.com' },
    { name: 'Jane', email: 'jane@example.com' },
    { name: 'Bob', email: 'bob@example.com' }
]);
```

With custom batch size:

```javascript
const insertedIds = await db.insertMultiple('users', rows, 500); // Batch size: 500
```

---

## ✏️ UPDATE

### Update Query

```javascript
const affectedRows = await db.update('users', 
    { name: 'John Updated', email: 'newemail@example.com' },
    { id: 1 }
);
```

### Fluent Update

```javascript
const affectedRows = await db.update('users')
    .where({ id: 2 })
    .change({ name: 'Jane Updated', status: 'active' });
```

---

## 🗑️ DELETE

### Delete Query

```javascript
const affectedRows = await db.delete('users', { id: 1 });
```

### Fluent Delete

```javascript
const affectedRows = await db.delete('users').where({ status: 'inactive' });
```

---

## 🚀 Advanced Features

### Raw Query Execution

```javascript
const result = await db.query('PRAGMA table_info(users)');
```

### Transactions & Batch Operations

```javascript
// Batch insert with automatic transaction handling
const ids = await db.insertMultiple('users', largeDataset, 1000);
```

### Parameter Binding (SQL Injection Prevention)

All methods support safe parameter binding:

```javascript
// Safe - parameters are bound separately
await db.select('SELECT * FROM users WHERE name = ? AND status = ?', ['John', 'active']);
```

### Error Handling

```javascript
try {
    const rows = await db.select('SELECT * FROM users');
} catch (error) {
    if (error instanceof BunnySecureDBException) {
        console.error('Database error:', error.message);
    }
}
```

---

## 📝 Method Reference

| Method | Description |
|--------|-------------|
| `getInstance(config)` | Initialize or get singleton instance |
| `resetInstance()` | Reset singleton instance |
| `createTable(name, columns, ifNotExists)` | Create a table |
| `dropTable(name)` | Drop a table |
| `select(sql, params)` | Execute SELECT query |
| `query(sql, params)` / `q(sql, params)` | Execute any query |
| `insert(table, data)` | Insert single row or start fluent insert |
| `insertNow(table, data)` | Insert single row immediately |
| `row(data)` | Add row in fluent insert chain |
| `insertMultiple(table, rows, batchSize)` | Batch insert multiple rows |
| `from(table, columns)` | Start fluent SELECT |
| `where(conditions)` | Add WHERE clause in fluent chain |
| `orderBy(column, direction)` | Add ORDER BY in fluent chain |
| `limit(count)` | Add LIMIT in fluent chain |
| `get()` | Execute fluent SELECT |
| `update(table, data, conditions)` | Update rows |
| `change(data)` | Execute update with WHERE clause in fluent chain |
| `delete(table, conditions)` | Delete rows |
| `execute()` | Execute delete/update in fluent chain |

---

## 🔗 Related Resources

- **PHP Version**: See `README.md`
- **Bunny CDN**: https://bunny.net?ref=9zzlt7jfxy
- **SQLite Documentation**: https://www.sqlite.org/docs.html

---

## 📄 License

MIT - Feel free to use in your projects!

[https://codewithmark.com](https://codewithmark.com)