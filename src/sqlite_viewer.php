<?php

$dbDirectory = '/var/www/html/admin/php_mc/src/private/db';
require_once __DIR__ . '/utils.php';

function format_bytes(int $bytes): string
{
        if ($bytes <= 0) {
                return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int)floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}

function sanitize_identifier(string $identifier): string
{
        return '"' . str_replace('"', '""', $identifier) . '"';
}

$dbFiles = array_values(array_filter(array_map('realpath', glob($dbDirectory . '/*.db') ?: [])));
if (!$dbFiles) {
        die('No database files found in the specified directory.');
}

$selectedDb = $_REQUEST['db_file'] ?? $dbFiles[0];
$selectedDb = realpath($selectedDb);
if ($selectedDb === false || !in_array($selectedDb, $dbFiles, true)) {
        $selectedDb = $dbFiles[0];
}

$selectedTable = $_REQUEST['table'] ?? null;
$limit = isset($_REQUEST['limit']) ? max(1, min((int)$_REQUEST['limit'], 500)) : 50;
$offset = isset($_REQUEST['offset']) ? max(0, (int)$_REQUEST['offset']) : 0;

try {
        $db = new SQLite3($selectedDb, SQLITE3_OPEN_READWRITE);
        $db->busyTimeout(2000);
        $db->exec('PRAGMA foreign_keys = ON');
} catch (Exception $e) {
        die('Failed to open database: ' . htmlspecialchars($e->getMessage()));
}

$tables = [];
$tableListResult = $db->query("SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name COLLATE NOCASE");
while ($row = $tableListResult->fetchArray(SQLITE3_ASSOC)) {
        $tables[$row['name']] = $row['type'];
}

if ($selectedTable && !isset($tables[$selectedTable])) {
        $selectedTable = null;
}

$lastQuery = $_REQUEST['query'] ?? '';
$queryRows = [];
$queryColumns = [];
$queryError = null;
$queryDurationMs = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_REQUEST['query'])) {
        $timerStart = microtime(true);
        try {
                $queryResult = $db->query($lastQuery);
                if ($queryResult === false) {
                        throw new RuntimeException($db->lastErrorMsg());
                }
                $firstRow = $queryResult->fetchArray(SQLITE3_ASSOC);
                if ($firstRow) {
                        $queryColumns = array_keys($firstRow);
                        $queryRows[] = $firstRow;
                        while ($row = $queryResult->fetchArray(SQLITE3_ASSOC)) {
                                $queryRows[] = $row;
                        }
                }
        } catch (Throwable $exception) {
                $queryError = $exception->getMessage();
        }
        $queryDurationMs = round((microtime(true) - $timerStart) * 1000, 2);
}

$schemaInfo = [];
$indexInfo = [];
$rowCount = null;
$previewRows = [];
$previewColumns = [];

if ($selectedTable) {
        $escapedTable = SQLite3::escapeString($selectedTable);

        $schemaResult = $db->query("PRAGMA table_info('$escapedTable')");
        while ($row = $schemaResult->fetchArray(SQLITE3_ASSOC)) {
                $schemaInfo[] = $row;
        }

        $indexListResult = $db->query("PRAGMA index_list('$escapedTable')");
        while ($indexRow = $indexListResult->fetchArray(SQLITE3_ASSOC)) {
            $indexName = $indexRow['name'];
            $indexDetails = [];
            $indexInfoResult = $db->query("PRAGMA index_info('" . SQLite3::escapeString($indexName) . "')");
            while ($col = $indexInfoResult->fetchArray(SQLITE3_ASSOC)) {
                $indexDetails[] = $col;
            }
            $indexRow['columns'] = $indexDetails;
            $indexInfo[] = $indexRow;
        }

        $countResult = $db->query("SELECT COUNT(*) AS total FROM " . sanitize_identifier($selectedTable));
        if ($countRow = $countResult->fetchArray(SQLITE3_ASSOC)) {
            $rowCount = (int)$countRow['total'];
        }

        if (isset($_GET['action']) && $_GET['action'] === 'download_csv') {
            $downloadColumns = array_map(function ($column) {
                return $column['name'];
            }, $schemaInfo);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . basename($selectedDb) . '_' . $selectedTable . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $out = fopen('php://output', 'wb');
            if ($downloadColumns) {
                fputcsv($out, $downloadColumns);
            }
            $downloadSql = 'SELECT * FROM ' . sanitize_identifier($selectedTable);
            $downloadResult = $db->query($downloadSql);
            if ($downloadResult !== false) {
                while ($row = $downloadResult->fetchArray(SQLITE3_ASSOC)) {
                    $normalized = [];
                    foreach ($downloadColumns as $columnName) {
                        $value = array_key_exists($columnName, $row) ? $row[$columnName] : null;
                        if (is_scalar($value) || is_null($value)) {
                            $normalized[] = $value;
                        } else {
                            $normalized[] = json_encode($value);
                        }
                    }
                    fputcsv($out, $normalized);
                }
            }
            fclose($out);
            exit;
        }

        $dataSql = sprintf(
            'SELECT * FROM %s LIMIT %d OFFSET %d',
            sanitize_identifier($selectedTable),
            $limit,
            $offset
        );

        $dataResult = $db->query($dataSql);
        if ($dataResult !== false) {
            $firstDataRow = $dataResult->fetchArray(SQLITE3_ASSOC);
            if ($firstDataRow) {
                $previewColumns = array_keys($firstDataRow);
                $previewRows[] = $firstDataRow;
                while ($row = $dataResult->fetchArray(SQLITE3_ASSOC)) {
                    $previewRows[] = $row;
                }
            }
        }

        if (!$previewColumns && $schemaInfo) {
            $previewColumns = array_map(function ($column) {
                return $column['name'];
            }, $schemaInfo);
        }
}

$dbMeta = [
        'path' => $selectedDb,
        'name' => basename($selectedDb),
        'size' => format_bytes((int)@filesize($selectedDb)),
        'tables' => count($tables),
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SQLite Viewer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/base16/tomorrow-night.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"></script>
    <script>hljs.highlightAll();</script>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap.min.js"></script>
    <style>
        body { background-color: #0d1117; color: #c9d1d9; }
        a { color: #58a6ff; }
        a:hover { color: #79c0ff; text-decoration: none; }
        .panel { background-color: rgba(22, 27, 34, 0.9); border-color: #30363d; }
        .panel-heading { background-color: #161b22 !important; border-color: #30363d !important; color: #c9d1d9 !important; }
        .panel-body { color: #c9d1d9; }
        .table > thead > tr > th { border-color: #30363d; color: #f0f6fc; }
        .table > tbody > tr > td { border-color: #30363d; }
        .btn-primary { background-color: #1f6feb; border-color: #388bfd; }
        .btn-primary:hover { background-color: #388bfd; border-color: #58a6ff; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #21262d; color: #8b949e; margin-left: 6px; font-size: 12px; }
        .nav-sidebar { max-height: 80vh; overflow-y: auto; }
        textarea { background-color: #161b22; color: #f0f6fc; border-color: #30363d; }
        .dataTables_wrapper .dataTables_paginate .paginate_button { color: #c9d1d9 !important; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #21262d !important; border-color: #30363d !important; }
        .label-table { background-color: #238636; margin-left: 6px; }
        .label-view { background-color: #8957e5; margin-left: 6px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <?php echo render_nav_menu(basename(__FILE__)); ?>
    <div class="row">
        <div class="col-sm-3">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Databases</strong></div>
                <div class="panel-body">
                    <form method="get" class="form-horizontal">
                        <div class="form-group">
                            <label for="db_file" class="control-label">Select database</label>
                            <select name="db_file" id="db_file" class="form-control" onchange="this.form.submit()">
                                <?php foreach ($dbFiles as $file): ?>
                                    <option value="<?php echo htmlspecialchars($file); ?>" <?php echo $file === $selectedDb ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(basename($file)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($selectedTable): ?>
                            <input type="hidden" name="table" value="<?php echo htmlspecialchars($selectedTable); ?>">
                        <?php endif; ?>
                    </form>
                    <hr>
                    <h5 class="text-uppercase">Tables &amp; Views <span class="pill"><?php echo (int)$dbMeta['tables']; ?></span></h5>
                    <ul class="nav nav-pills nav-stacked nav-sidebar">
                        <?php foreach ($tables as $tableName => $type): ?>
                            <li class="<?php echo $selectedTable === $tableName ? 'active' : ''; ?>">
                                <a href="?db_file=<?php echo urlencode($selectedDb); ?>&amp;table=<?php echo urlencode($tableName); ?>">
                                    <?php echo htmlspecialchars($tableName); ?>
                                    <span class="label <?php echo $type === 'view' ? 'label-view' : 'label-table'; ?>"><?php echo htmlspecialchars(strtoupper($type)); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-sm-9">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>Database Overview</strong></div>
                <div class="panel-body">
                    <dl class="dl-horizontal">
                        <dt>Name</dt><dd><?php echo htmlspecialchars($dbMeta['name']); ?></dd>
                        <dt>Path</dt><dd><code><?php echo htmlspecialchars($dbMeta['path']); ?></code></dd>
                        <dt>Size</dt><dd><?php echo htmlspecialchars($dbMeta['size']); ?></dd>
                    </dl>
                </div>
            </div>

            <?php if ($selectedTable): ?>
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <strong>Table: <?php echo htmlspecialchars($selectedTable); ?></strong>
                        <?php if ($rowCount !== null): ?>
                            <span class="pill">Rows: <?php echo number_format($rowCount); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="panel-body">
                        <?php if ($schemaInfo): ?>
                            <h4>Schema</h4>
                            <div class="table-responsive">
                                <table class="table table-condensed table-striped">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Column</th>
                                            <th>Type</th>
                                            <th>Not null</th>
                                            <th>Default</th>
                                            <th>PK</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($schemaInfo as $column): ?>
                                        <tr>
                                            <td><?php echo (int)$column['cid']; ?></td>
                                            <td><?php echo htmlspecialchars($column['name']); ?></td>
                                            <td><?php echo htmlspecialchars($column['type']); ?></td>
                                            <td><?php echo $column['notnull'] ? 'Yes' : 'No'; ?></td>
                                            <td><?php echo htmlspecialchars((string)$column['dflt_value']); ?></td>
                                            <td><?php echo $column['pk'] ? 'Yes' : 'No'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if ($indexInfo): ?>
                            <h4>Indexes</h4>
                            <div class="table-responsive">
                                <table class="table table-condensed table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Unique</th>
                                            <th>Columns</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($indexInfo as $index): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($index['name']); ?></td>
                                            <td><?php echo $index['unique'] ? 'Yes' : 'No'; ?></td>
                                            <td>
                                                                        <?php
                                                                            $columnNames = array_map(function ($col) {
                                                                                    return $col['name'];
                                                                            }, $index['columns']);
                                                                            echo htmlspecialchars(implode(', ', $columnNames));
                                                                        ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                                                <h4 class="clearfix">
                                                    Latest Rows
                                                    <?php
                                                        $prevDisabled = $offset === 0;
                                                        $nextDisabled = ($rowCount !== null && $offset + $limit >= $rowCount);
                                                        $prevParams = [
                                                                'db_file' => $selectedDb,
                                                                'table'   => $selectedTable,
                                                                'limit'   => $limit,
                                                                'offset'  => max($offset - $limit, 0),
                                                        ];
                                                        $nextParams = [
                                                                'db_file' => $selectedDb,
                                                                'table'   => $selectedTable,
                                                                'limit'   => $limit,
                                                                'offset'  => $offset + $limit,
                                                        ];
                                                        $exportParams = [
                                                                'db_file' => $selectedDb,
                                                                'table'   => $selectedTable,
                                                                'action'  => 'download_csv',
                                                        ];
                                                    ?>
                                                    <div class="btn-group pull-right">
                                                        <a class="btn btn-xs btn-default<?php echo $prevDisabled ? ' disabled' : ''; ?>" href="<?php echo $prevDisabled ? '#' : htmlspecialchars('?' . http_build_query($prevParams)); ?>" aria-disabled="<?php echo $prevDisabled ? 'true' : 'false'; ?>">Prev</a>
                                                        <a class="btn btn-xs btn-default<?php echo $nextDisabled ? ' disabled' : ''; ?>" href="<?php echo $nextDisabled ? '#' : htmlspecialchars('?' . http_build_query($nextParams)); ?>" aria-disabled="<?php echo $nextDisabled ? 'true' : 'false'; ?>">Next</a>
                                                        <a class="btn btn-xs btn-primary" href="<?php echo htmlspecialchars('?' . http_build_query($exportParams)); ?>">Download CSV</a>
                                                    </div>
                                                </h4>
                        <form class="form-inline" method="get" style="margin-bottom: 10px;">
                            <input type="hidden" name="db_file" value="<?php echo htmlspecialchars($selectedDb); ?>">
                            <input type="hidden" name="table" value="<?php echo htmlspecialchars($selectedTable); ?>">
                            <div class="form-group">
                                <label for="limit">Limit</label>
                                <input type="number" min="1" max="500" name="limit" id="limit" class="form-control input-sm" value="<?php echo (int)$limit; ?>">
                            </div>
                            <div class="form-group" style="margin-left:10px;">
                                <label for="offset">Offset</label>
                                <input type="number" min="0" name="offset" id="offset" class="form-control input-sm" value="<?php echo (int)$offset; ?>">
                            </div>
                            <button type="submit" class="btn btn-sm btn-default">Apply</button>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="table-preview">
                                <thead>
                                <tr>
                                    <?php foreach ($previewColumns as $columnName): ?>
                                        <th><?php echo htmlspecialchars($columnName); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($previewRows as $row): ?>
                                    <tr>
                                        <?php foreach ($previewColumns as $columnName): ?>
                                            <td><?php echo htmlspecialchars((string)$row[$columnName]); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$previewRows): ?>
                                    <tr>
                                        <td colspan="<?php echo max(count($previewColumns), 1); ?>" class="text-muted">No rows found.</td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="panel panel-default">
                <div class="panel-heading"><strong>Run SQL</strong></div>
                <div class="panel-body">
                    <?php if ($queryError): ?>
                        <div class="alert alert-danger">
                            <strong>Error:</strong> <?php echo htmlspecialchars($queryError); ?>
                        </div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="db_file" value="<?php echo htmlspecialchars($selectedDb); ?>">
                        <?php if ($selectedTable): ?>
                            <input type="hidden" name="table" value="<?php echo htmlspecialchars($selectedTable); ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="query">SQL</label>
                            <textarea name="query" id="query" rows="6" class="form-control" placeholder="SELECT * FROM <?php echo $selectedTable ? htmlspecialchars($selectedTable) : 'table_name'; ?> LIMIT 50;"><?php echo htmlspecialchars($lastQuery); ?></textarea>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Run query</button>
                            <?php if ($selectedTable): ?>
                                <?php
                                  $quickSelectSql = 'SELECT * FROM ' . sanitize_identifier($selectedTable) . ' LIMIT 100';
                                  $quickParams = [
                                      'db_file' => $selectedDb,
                                      'table'   => $selectedTable,
                                      'query'   => $quickSelectSql,
                                  ];
                                ?>
                                <a class="btn btn-default" href="<?php echo htmlspecialchars('?' . http_build_query($quickParams)); ?>">Quick select</a>
                            <?php endif; ?>
                            <?php if ($queryDurationMs !== null): ?>
                                <span class="text-muted" style="margin-left:10px;">Took <?php echo $queryDurationMs; ?> ms</span>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($queryRows): ?>
                        <h4>Results (<?php echo count($queryRows); ?> rows)</h4>
                        <div class="table-responsive">
                            <table class="table table-striped" id="query-results">
                                <thead>
                                    <tr>
                                        <?php foreach ($queryColumns as $colName): ?>
                                            <th><?php echo htmlspecialchars($colName); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queryRows as $row): ?>
                                        <tr>
                                            <?php foreach ($queryColumns as $colName): ?>
                                                <td><?php echo htmlspecialchars((string)$row[$colName]); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif ($lastQuery && !$queryError): ?>
                        <div class="alert alert-info">Query executed successfully (no rows to display).</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    $(function() {
        var $queryTable = $('#query-results');
        if ($queryTable.length) {
            $queryTable.DataTable({
                pageLength: 25,
                order: [],
                stateSave: true
            });
        }

        var $previewTable = $('#table-preview');
        if ($previewTable.length) {
            $previewTable.DataTable({
                paging: false,
                searching: true,
                info: false,
                ordering: true
            });
        }
    });
</script>
</body>
</html>