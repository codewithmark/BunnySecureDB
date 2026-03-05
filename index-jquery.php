<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRUD Demo (jQuery + BunnySecureDB.js)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">Users CRUD (jQuery + BunnySecureDB.js)</h1>
 

            <div class="row g-3 mb-3">
                <div class="col-md-2">
                    <label for="userId" class="form-label">ID</label>
                    <input type="number" id="userId" class="form-control" placeholder="For Read/Update/Delete">
                </div>
                <div class="col-md-5">
                    <label for="userName" class="form-label">Name</label>
                    <input type="text" id="userName" class="form-control" placeholder="Enter name">
                </div>
                <div class="col-md-5">
                    <label for="userEmail" class="form-label">Email</label>
                    <input type="email" id="userEmail" class="form-control" placeholder="Enter email">
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <button class="btn btn-primary" id="btnCreate">Create</button>
                <button class="btn btn-info text-white" id="btnRead">Read</button>
                <button class="btn btn-secondary" id="btnGetAll">Get All</button>
                <button class="btn btn-warning" id="btnUpdate">Update</button>
                <button class="btn btn-danger" id="btnDelete">Delete</button>
                <button class="btn btn-outline-danger" id="btnDropTable">Drop Table</button>
                <button class="btn btn-outline-success" id="btnCreateTable">Create Table</button>
            </div>

            <div id="status" class="alert d-none" role="alert"></div>

            <div class="table-responsive">
                <table class="table table-striped table-bordered mb-0">
                    <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                    </tr>
                    </thead>
                    <tbody id="resultBody">
                    <tr>
                        <td colspan="3" class="text-center text-muted">No data loaded yet.</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="BunnyCDN-DB.js"></script>
<script>
    const DB_ENDPOINT = 'libsql://01KJXDSC9FCTHXQCTGD5AM3A1R-test-v2.lite.bunnydb.net/';
    const DB_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJFZERTQSJ9.eyJwIjp7InJvIjpudWxsLCJydyI6eyJucyI6WyJ0ZXN0LXYyIl0sInRhZ3MiOm51bGx9LCJyb2EiOm51bGwsInJ3YSI6bnVsbCwiZGRsIjpudWxsfSwiaWF0IjoxNzcyNjgxNDY3fQ.o5DTPA9mhzdwFTFU8RLWeTPFFPV4pttD3LgebWR9BMHakqt5Gw5ch-WKrLA0W73gGnyTHRucwrqXKJrx4DBOCw';

    const TABLE_NAME = 'users';

    let db = null;

    function showStatus(success, message) {
        const $status = $('#status');
        $status.removeClass('d-none alert-success alert-danger')
            .addClass(success ? 'alert-success' : 'alert-danger')
            .text(message);
    }

    function renderRows(rows) {
        const $body = $('#resultBody');
        $body.empty();

        if (!Array.isArray(rows) || rows.length === 0) {
            $body.append('<tr><td colspan="3" class="text-center text-muted">No records found.</td></tr>');
            return;
        }

        rows.forEach(function (row) {
            $body.append('<tr><td>' + (row.id ?? '') + '</td><td>' + (row.name ?? '') + '</td><td>' + (row.email ?? '') + '</td></tr>');
        });
    }

    function getFormData() {
        return {
            id: Number($('#userId').val() || 0),
            name: String($('#userName').val() || '').trim(),
            email: String($('#userEmail').val() || '').trim()
        };
    }

    async function readAll() {
        const rows = await db.select('SELECT id, name, email FROM users ORDER BY id DESC');
        console.log ( rows);
        renderRows(rows);
        showStatus(true, 'All records fetched successfully.');
    }

    async function createUser() {
        const data = getFormData();
        if (!data.name || !data.email) {
            throw new Error('Name and email are required.');
        }

        await db.insert(TABLE_NAME, {
            name: data.name,
            email: data.email
        });

        await readAll();
        showStatus(true, 'Record created successfully.');
    }

    async function readUser() {
        const data = getFormData();
        if (data.id > 0) {
            const rows = await db.select('SELECT id, name, email FROM users WHERE id = ?', [data.id]);
            console.log ( rows); 
            renderRows(rows);
            showStatus(true, 'Record fetched successfully.');
            return;
        }

        await readAll();
    }

    async function updateUser() {
        const data = getFormData();
        if (data.id <= 0 || !data.name || !data.email) {
            throw new Error('ID, name, and email are required for update.');
        }

        await db
            .update(TABLE_NAME)
            .where({ id: data.id })
            .change({
                name: data.name,
                email: data.email
            });

        await readAll();
        showStatus(true, 'Record updated successfully.');
    }

    async function deleteUser() {
        const data = getFormData();
        if (data.id <= 0) {
            throw new Error('Valid ID is required for delete.');
        }

        await db.delete(TABLE_NAME, { id: data.id });
        await readAll();
        showStatus(true, 'Record deleted successfully.');
    }

    async function createTable() {
        await db.createTable(TABLE_NAME, {
            id: 'INTEGER PRIMARY KEY AUTOINCREMENT',
            name: 'TEXT NOT NULL',
            email: 'TEXT NOT NULL',
            created_dttm: 'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
        }, true);

        await readAll();
        showStatus(true, 'Table created successfully.');
    }

    async function dropTable() {
        await db.dropTable(TABLE_NAME, true);
        renderRows([]);
        showStatus(true, 'Table dropped successfully.');
    }

    async function runAction(actionFn) {
        try {
            if (!db) {
                db = BunnySecureDB.getInstance({
                    endpoint: DB_ENDPOINT,
                    token: DB_TOKEN,
                    timeout: 20
                });
            }

            await actionFn();
        } catch (error) {
            const message = error && error.message ? error.message : 'Request failed.';
            showStatus(false, message);
        }
    }

    $('#btnCreate').on('click', function () {
        runAction(createUser);
    });

    $('#btnRead').on('click', function () {
        runAction(readUser);
    });

    $('#btnGetAll').on('click', function () {
        runAction(readAll);
    });

    $('#btnUpdate').on('click', function () {
        runAction(updateUser);
    });

    $('#btnDelete').on('click', function () {
        runAction(deleteUser);
    });

    $('#btnCreateTable').on('click', function () {
        runAction(createTable);
    });

    $('#btnDropTable').on('click', function () {
        Swal.fire({
            title: 'Drop table?',
            text: 'Are you sure you want to drop table users? This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, drop it',
            cancelButtonText: 'Cancel'
        }).then(function (result) {
            if (result.isConfirmed) {
                runAction(dropTable);
            }
        });
    });

    runAction(readAll);
</script>
</body>
</html>
