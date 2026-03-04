<?php

require_once __DIR__ . '/BunnyCDN-DB.php';

function db_connect() {
    return BunnySecureDB::getInstance([
        'endpoint' => 'libsql://xxx.lite.bunnydb.net/',
        'token'    => 'YOUR_RW_OR_RO_TOKEN'
    ]);
}
const TABLE_NAME = 'users';

function db_select(?int $id = null): array {
    $db = db_connect();
    if ($id !== null) {
        return $db->select('SELECT * FROM ' . TABLE_NAME . ' WHERE id = ?', [$id]);
    }
    return $db->select('SELECT * FROM ' . TABLE_NAME . ' ORDER BY id DESC');
}

function db_insert(string $name, string $email): int {
    $db = db_connect();
    return $db->insert(TABLE_NAME, [
        'name' => $name,
        'email' => $email,
    ]);
}

function db_update(int $id, string $name, string $email): int {
    $db = db_connect();
    return $db
    ->update(TABLE_NAME)
    ->where(['id' => $id])
    ->change([
        'name' => $name,
        'email' => $email,
    ]);
}

function db_delete(int $id): int {
    $db = db_connect();
    return $db->delete(TABLE_NAME, ['id' => $id]);
}

function db_drop_table(): bool {
    $db = db_connect();
    return $db->dropTable(TABLE_NAME, true);
}

function db_create_table(): bool {
    $db = db_connect();
    return $db->createTable(TABLE_NAME, [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'name' => 'TEXT NOT NULL',
        'email' => 'TEXT NOT NULL',
        'created_dttm' => 'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
    ], true);
}

function json_response(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = trim((string)$_POST['action']);

        switch ($action) {
            case 'create':
                $name = trim((string)($_POST['name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));

                if ($name === '' || $email === '') {
                    json_response(false, 'Name and email are required.');
                }

                $insertId = db_insert($name, $email);
                json_response(true, 'Record created successfully.', ['insert_id' => $insertId]);
                break;

            case 'read':
                $idRaw = trim((string)($_POST['id'] ?? ''));
                $id = $idRaw !== '' ? (int)$idRaw : null;
                $rows = db_select($id);
                json_response(true, 'Record(s) fetched successfully.', ['rows' => $rows]);
                break;

            case 'read_all':
                $rows = db_select(null);
                json_response(true, 'All records fetched successfully.', ['rows' => $rows]);
                break;

            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));

                if ($id <= 0 || $name === '' || $email === '') {
                    json_response(false, 'ID, name, and email are required for update.');
                }

                $updated = db_update($id, $name, $email);
                json_response(true, 'Record updated successfully.', ['updated' => $updated]);
                break;

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);

                if ($id <= 0) {
                    json_response(false, 'Valid ID is required for delete.');
                }

                $deleted = db_delete($id);
                json_response(true, 'Record deleted successfully.', ['deleted' => $deleted]);
                break;

            case 'drop_table':
                $dropped = db_drop_table();
                json_response(true, 'Table dropped successfully.', ['dropped' => $dropped]);
                break;

            case 'create_table':
                $created = db_create_table();
                json_response(true, 'Table created successfully.', ['created' => $created]);
                break;

            default:
                json_response(false, 'Invalid action supplied.');
        }
    } catch (Exception $e) {
        json_response(false, $e->getMessage());
    }
}

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRUD AJAX Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">Users CRUD (AJAX + PHP)</h1>

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
<script>
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
            $body.append('<tr><td>' + row.id + '</td><td>' + row.name + '</td><td>' + row.email + '</td></tr>');
        });
    }

    function sendCrud(action) {
        $.ajax({
            url: 'index.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: action,
                id: $('#userId').val(),
                name: $('#userName').val(),
                email: $('#userEmail').val()
            }
        }).done(function (res) {
            showStatus(res.success, res.message);

            if ((action === 'read' || action === 'read_all') && res.success) {
                renderRows((res.data && res.data.rows) ? res.data.rows : []);
            }

            if ((action === 'create' || action === 'update' || action === 'delete') && res.success) {
                sendCrud('read_all');
            }

            if (action === 'drop_table' && res.success) {
                renderRows([]);
            }

            if (action === 'create_table' && res.success) {
                sendCrud('read_all');
            }
        }).fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Request failed.';
            showStatus(false, message);
        });
    }

    $('#btnCreate').on('click', function () { sendCrud('create'); });
    $('#btnRead').on('click', function () { sendCrud('read'); });
    $('#btnGetAll').on('click', function () { sendCrud('read_all'); });
    $('#btnUpdate').on('click', function () { sendCrud('update'); });
    $('#btnDelete').on('click', function () { sendCrud('delete'); });
    $('#btnDropTable').on('click', function () {
        Swal.fire({
            title: 'Drop table?',
            text: 'Are you sure you want to drop table users1? This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, drop it',
            cancelButtonText: 'Cancel'
        }).then(function (result) {
            if (result.isConfirmed) {
                sendCrud('drop_table');
            }
        });
    });
    $('#btnCreateTable').on('click', function () { sendCrud('create_table'); });

    sendCrud('read_all');
</script>
</body>
</html>  
