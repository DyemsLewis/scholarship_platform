<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple GWA Extractor</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
        .upload-area { 
            border: 2px dashed #ccc; 
            padding: 30px; 
            text-align: center; 
            margin: 20px 0;
            cursor: pointer;
        }
        .upload-area:hover { background: #f0f0f0; }
        .gwa-result {
            font-size: 72px;
            font-weight: bold;
            text-align: center;
            padding: 30px;
            margin: 20px 0;
            border-radius: 10px;
        }
        .gwa-1 { background: #27ae60; color: white; } /* Excellent */
        .gwa-2 { background: #f39c12; color: white; } /* Good */
        .gwa-3 { background: #e67e22; color: white; } /* Passing */
        .gwa-4 { background: #e74c3c; color: white; } /* Failing */
        .gwa-5 { background: #c0392b; color: white; } /* Failed */
        .debug { 
            background: #f8f9fa; 
            padding: 10px; 
            font-family: monospace;
            font-size: 12px;
            border: 1px solid #ddd;
            margin-top: 20px;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <h1>🎓 Simple GWA Extractor</h1>
    <p>Upload grade document to extract GWA only (1.0 = highest, 5.0 = lowest)</p>
    
    <div class="upload-area" id="uploadArea">
        <h3>📤 Click or Drop File Here</h3>
        <p>JPG, PNG, PDF (Max 5MB)</p>
        <input type="file" id="fileInput" accept=".jpg,.jpeg,.png,.pdf" style="display: none;">
    </div>
    
    <div id="result"></div>
    <div id="debug" class="debug"></div>

    <script>
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('fileInput');
    
    uploadArea.onclick = () => fileInput.click();
    
    uploadArea.ondragover = (e) => {
        e.preventDefault();
        uploadArea.style.background = '#e8f4ff';
    };
    
    uploadArea.ondragleave = () => {
        uploadArea.style.background = '';
    };
    
    uploadArea.ondrop = (e) => {
        e.preventDefault();
        uploadArea.style.background = '';
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            processFile(e.dataTransfer.files[0]);
        }
    };
    
    fileInput.onchange = () => {
        if (fileInput.files[0]) processFile(fileInput.files[0]);
    };
    
    function processFile(file) {
        document.getElementById('result').innerHTML = '<div class="spinner"></div><p style="text-align:center">Processing...</p>';
        document.getElementById('debug').innerHTML = '';
        
        const formData = new FormData();
        formData.append('grade_file', file);
        
        fetch('simple_gwa_process.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let gwaClass = 'gwa-1';
                if (data.gwa > 4.0) gwaClass = 'gwa-5';
                else if (data.gwa > 3.0) gwaClass = 'gwa-4';
                else if (data.gwa > 2.0) gwaClass = 'gwa-3';
                else if (data.gwa > 1.5) gwaClass = 'gwa-2';
                
                // Check if conversion happened
                let conversionInfo = '';
                if (data.original_value != data.gwa) {
                    conversionInfo = `<div style="font-size: 16px; margin-top: 10px;">
                        Original: ${data.original_value} → Converted to: ${data.gwa}
                    </div>`;
                }
                
                // Check if multiple averages were found
                let multipleAvgInfo = '';
                if (data.multiple_averages && data.multiple_averages.length > 1) {
                    multipleAvgInfo = `
                        <div style="font-size: 14px; margin-top: 10px; background: #fff3cd; padding: 10px; border-radius: 5px;">
                            <strong>📊 Multiple Averages Found:</strong><br>
                            Values: ${data.multiple_averages.join(', ')}<br>
                            Average: ${data.original_value} → Final GWA: ${data.gwa}
                        </div>
                    `;
                }
                
                document.getElementById('result').innerHTML = `
                    <div class="gwa-result ${gwaClass}">
                        ${data.gwa}
                        ${conversionInfo}
                        ${multipleAvgInfo}
                    </div>
                `;
                
                // Show debug info
                document.getElementById('debug').innerHTML = `
                    <strong>Debug:</strong><br>
                    File: ${data.file.original_name}<br>
                    Original Value: ${data.original_value}<br>
                    Multiple Averages: ${data.multiple_averages ? data.multiple_averages.join(', ') : 'None'}<br>
                    Converted GWA: ${data.gwa}<br>
                    Method: ${data.method}<br>
                    Time: ${new Date().toLocaleTimeString()}
                `;
            } else {
                document.getElementById('result').innerHTML = `
                    <div style="color: red; text-align: center; padding: 20px;">
                        ❌ Error: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('result').innerHTML = `
                <div style="color: red; text-align: center; padding: 20px;">
                    ❌ Error: ${error.message}
                </div>
            `;
        });
    }
    </script>
</body>
</html>