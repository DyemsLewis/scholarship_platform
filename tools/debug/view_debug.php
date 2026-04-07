<?php
$logFile = __DIR__ . '/../../storage/logs/ocr_debug.log';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR Debug Viewer</title>
    <style>
        body {
            font-family: monospace;
            padding: 20px;
            background: #1e1e1e;
            color: #d4d4d4;
        }

        pre {
            background: #2d2d2d;
            padding: 15px;
            border-radius: 5px;
            overflow: auto;
            max-height: 600px;
            border: 1px solid #404040;
        }

        button {
            padding: 10px 20px;
            margin: 10px 5px 10px 0;
            background: #007acc;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }

        button:hover {
            background: #005999;
        }
    </style>
</head>
<body>
    <h1>OCR Debug Log</h1>
    <button onclick="refreshLog()">Refresh</button>
    <button onclick="clearLog()">Clear Log</button>
    <button onclick="testTesseract()">Test Tesseract</button>

    <div id="log-container">
        <pre id="log-content">Loading...</pre>
    </div>

    <script>
        function refreshLog() {
            fetch('get_debug_log.php')
                .then(response => response.text())
                .then(data => {
                    document.getElementById('log-content').textContent = data;
                });
        }

        function clearLog() {
            fetch('clear_debug_log.php').then(() => refreshLog());
        }

        function testTesseract() {
            fetch('test_tesseract.php')
                .then(response => response.json())
                .then(data => {
                    alert(JSON.stringify(data, null, 2));
                    refreshLog();
                });
        }

        setInterval(refreshLog, 5000);
        refreshLog();
    </script>
</body>
</html>
