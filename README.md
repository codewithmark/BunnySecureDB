# 🐰 BunnySecureDB


> A SecureDB-style PHP wrapper for Bunny.net Database  
> Simple. Fluent. SQLite-compatible. Built for web developers.

BunnySecureDB is a lightweight PHP class that connects to ** [https://bunny.net](https://bunny.net?ref=9zzlt7jfxy) Database (libSQL / SQLite compatible)** using the HTTP SQL API.

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

---

## 📦 Requirements

- PHP 8.0+
- cURL enabled
- Bunny.net Database instance
- Bunny Database access token

---

## 🔧 Installation

### 1️⃣ Download the class

Copy `BunnySecureDB.php` into your project.

### 2️⃣ Include it

```php
require_once 'BunnySecureDB.php';
```

---

## 🔐 Setup & Configuration

```php
$db = BunnySecureDB::getInstance([
    'endpoint' => 'libsql://xxx.lite.bunnydb.net/',  
    'token'    => 'YOUR_ACCESS_TOKEN'
]);
```

You can find:

- `endpoint` → [Bunny Database dashboard](https://bunny.net?ref=9zzlt7jfxy)
- `token` → Access token section


---

# 🏗 CREATE TABLE

## Create Table

```php
$success = $db->createTable('users', [
    'id'    => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name'  => 'TEXT NOT NULL',
    'email' => 'TEXT UNIQUE',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);
```

Pass `false` as third argument to create without "IF NOT EXISTS" check:

```php
$db->createTable('users', [...], false); // Create or fail
```

Returns `true` on success.

---

# 🗑 DROP TABLE

## Drop Table

```php
$success = $db->dropTable('users');
```

Alias method `deleteTable()` also available:

```php
$success = $db->deleteTable('users');
```

Pass `false` as second argument to drop without "IF EXISTS" check:

```php
$db->dropTable('users', false); // Drop or fail
```

Returns `true` on success.


---

# 📖 SELECT

## Simple SELECT

```php
$users = $db->select(
    "SELECT * FROM users WHERE role = ?",
    ['admin']
);

print_r($users);
```

---

## Fluent SELECT

```php
$users = $db
    ->from('users')
    ->where(['role' => 'admin'])
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->get();
```

---

# ✏️ INSERT

## Quick Insert

```php
$id = $db->insert('users', [
    'name'  => 'Mark',
    'email' => 'mark@example.com'
]);

echo $id; // Last Insert ID
```

---

## Fluent Insert

```php
$id = $db
    ->insert('users')
    ->row([
        'name'  => 'Alice',
        'email' => 'alice@example.com'
    ]);
```

---

# 📦 BULK INSERT (Batch Safe)

```php
$total = $db
    ->insertMultiple('users')
    ->batch(500)
    ->rows([
        ['name' => 'User1', 'email' => 'u1@test.com'],
        ['name' => 'User2', 'email' => 'u2@test.com'],
    ]);

echo $total; // Rows inserted
```

✔ Automatically wrapped in transaction  
✔ Handles large datasets safely  

---

# 🔄 UPDATE

## Quick Update

```php
$updated = $db->update('users',
    ['name' => 'Updated Name'],
    ['id' => 5]
);
```

---

## Fluent Update

```php
$updated = $db
    ->update('users')
    ->where(['id' => 5])
    ->change([
        'name' => 'New Name'
    ]);
```

Returns number of affected rows.

---

# 🗑 DELETE

## Quick Delete

```php
$deleted = $db->delete('users', [
    'id' => 5
]);
```

---

## Fluent Delete

```php
$deleted = $db
    ->delete('users')
    ->where(['id' => 5]);
```

Returns number of deleted rows.



# 🧪 Raw Query Helper

```php
$result = $db->q("PRAGMA table_info(users)");
```

Automatically detects query type:

| Query Type | Return Value |
|------------|-------------|
| SELECT     | Array of rows |
| INSERT     | Last Insert ID |
| UPDATE     | Affected rows |
| DELETE     | Affected rows |
| DDL        | `true` |

---

# 🏗 Full CRUD Example

```php
// CREATE
$userId = $db->insert('users')->row([
    'name'  => 'Demo User',
    'email' => 'demo@test.com'
]);

// READ
$user = $db->from('users')
           ->where(['id' => $userId])
           ->get();

// UPDATE
$db->update('users')
   ->where(['id' => $userId])
   ->change(['name' => 'Updated User']);

// DELETE
$db->delete('users')
   ->where(['id' => $userId]);
```

---

# 🔒 Security

- Uses parameter binding
- Prevents SQL injection
- Validates table & column names
- Safe identifier quoting

---

# ⚡ Why BunnySecureDB?

- SQLite simplicity
- Globally distributed database
- No server management
- Perfect for SaaS apps
- Clean fluent API
- Similar style to SecureDB

Great for:

- Micro SaaS
- APIs
- Admin dashboards
- Prototypes
- Edge applications

---

# 🧩 Roadmap

- [ ] Composer package
- [ ] Retry & exponential backoff
- [ ] Query logging
- [ ] Debug mode
- [ ] Migration helper

---

# 🤝 Contributing

Pull requests welcome.  
Keep it simple. Keep it clean.

---

# 📜 License

MIT License

---

[https://codewithmark.com](https://codewithmark.com)
