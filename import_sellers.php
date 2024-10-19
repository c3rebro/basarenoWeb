<?php
// Include the existing config.php to use its functions and constants
require_once 'config.php';

function sanitize_input($input) {
    // Remove non-printable characters
    $input = preg_replace('/[^\x20-\x7E]/', '', $input);
    // Trim whitespace
    $input = trim($input);
    return $input;
}

function sanitize_id($input) {
    // Remove non-numeric characters and trim whitespace
    $input = preg_replace('/\D/', '', $input);
    $input = trim($input);
    return $input;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_import'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $delimiter = $_POST['delimiter'];
    $encoding = $_POST['encoding'];
    $handle = fopen($file, 'r');

    if ($handle !== FALSE) {
        $conn = get_db_connection();
        while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            // Convert encoding if necessary
            if ($encoding === 'ansi') {
                $data = array_map(function($field) {
                    return iconv('windows-1252', 'UTF-8//IGNORE', $field);
                }, $data);
            }

            // Sanitize all fields
            $data = array_map('sanitize_input', $data);

            // Sanitize seller_id specifically
            $data[0] = sanitize_id($data[0]);

            // Skip rows with missing mandatory fields
            if (empty($data[1]) || empty($data[5])) {
                continue;
            }

            // Extract fields
            $seller_id = $data[0];
            $family_name = $data[1];
            $given_name = $data[2] ?: "Nicht angegeben";
            $city = $data[3] ?: "Nicht angegeben";
            $phone = $data[4] ?: "Nicht angegeben";
            $email = $data[5];

            // Handle email address formats
            if (preg_match('/<(.+)>/', $email, $matches)) {
                $email = $matches[1];
            }

            $reserved = 0; // Default value for reserved field
            $street = "Nicht angegeben"; // Default value for street
            $house_number = "Nicht angegeben"; // Default value for house_number
            $zip = "Nicht angegeben"; // Default value for zip

            // Generate a secure hash using the seller's email and ID
            $hash = hash('sha256', $email . $seller_id . SECRET);

            // Set verification token to NULL and verified to 1
            $verification_token = NULL;
            $verified = 1;

            $sql = "INSERT INTO sellers (id, email, reserved, verification_token, family_name, given_name, phone, street, house_number, zip, city, hash, verified) 
                    VALUES ('$seller_id', '$email', '$reserved', '$verification_token', '$family_name', '$given_name', '$phone', '$street', '$house_number', '$zip', '$city', '$hash', '$verified')";

            if ($conn->query($sql) !== TRUE) {
                echo "Error importing seller with email $email: " . $conn->error;
            }
        }

        fclose($handle);
        echo "Sellers imported successfully.";
    } else {
        echo "Error opening the CSV file.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Import Sellers</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            text-align: center;
            color: #333;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        label {
            font-weight: bold;
        }
        input[type="file"] {
            padding: 10px;
        }
        button {
            padding: 10px;
            background-color: #28a745;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background-color: #218838;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }
            table, th, td {
                font-size: 12px;
            }
        }
    </style>
    <script>
        let currentFile = null;

        function previewCSV() {
            if (!currentFile) return;

            const delimiter = document.querySelector('input[name="delimiter"]:checked').value;
            const encoding = document.querySelector('input[name="encoding"]:checked').value;

            const reader = new FileReader();
            reader.onload = function(e) {
                let contents = e.target.result;
                if (encoding === 'ansi') {
                    contents = new TextDecoder('windows-1252').decode(new Uint8Array(contents));
                }
                const rows = contents.split('\n');
                let html = '<table><thead><tr><th>Nr.</th><th>Family Name</th><th>Given Name</th><th>City</th><th>Phone</th><th>Email</th></tr></thead><tbody>';
                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].split(delimiter).map(cell => cell.trim());
                    if (cells.length > 1 && cells[1] && cells[5]) {
                        // Handle email address formats
                        let email = cells[5];
                        if (email.includes('<')) {
                            email = email.match(/<(.+)>/)[1];
                        }
                        html += '<tr>';
                        html += `<td>${cells[0].replace(/\D/g, '')}</td>`;
                        html += `<td>${cells[1]}</td>`;
                        html += `<td>${cells[2] || "Nicht angegeben"}</td>`;
                        html += `<td>${cells[3] || "Nicht angegeben"}</td>`;
                        html += `<td>${cells[4] || "Nicht angegeben"}</td>`;
                        html += `<td>${email}</td>`;
                        html += '</tr>';
                    }
                }
                html += '</tbody></table>';
                document.getElementById('preview').innerHTML = html;
                document.getElementById('confirm_import').style.display = 'block';
            };
            if (encoding === 'ansi') {
                reader.readAsArrayBuffer(currentFile);
            } else {
                reader.readAsText(currentFile, 'UTF-8');
            }
        }

        function handleFileSelect(event) {
            currentFile = event.target.files[0];
            previewCSV();
        }

        function handleOptionChange() {
            previewCSV();
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('#csv_file').addEventListener('change', handleFileSelect);
            document.querySelectorAll('input[name="delimiter"]').forEach(function(elem) {
                elem.addEventListener('change', handleOptionChange);
            });
            document.querySelectorAll('input[name="encoding"]').forEach(function(elem) {
                elem.addEventListener('change', handleOptionChange);
            });
        });
    </script>
</head>
<body>
    <div class="container">
        <h1>Import Sellers from CSV</h1>
        <form enctype="multipart/form-data" method="post" action="">
            <label for="csv_file">Choose CSV file:</label>
            <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
            <div>
                <label><input type="radio" name="delimiter" value="," checked> Comma</label>
                <label><input type="radio" name="delimiter" value=";"> Semicolon</label>
            </div>
            <div>
                <label><input type="radio" name="encoding" value="utf-8" checked> UTF-8</label>
                <label><input type="radio" name="encoding" value="ansi"> ANSI</label>
            </div>
            <div id="preview"></div>
            <button type="submit" name="confirm_import" id="confirm_import" style="display:none;">Confirm and Import</button>
        </form>

        <h2>Expected CSV File Structure</h2>
        <p>The imported CSV file must not contain column headers.</p>
        <table>
            <thead>
                <tr>
                    <th>Nr.</th>
                    <th>Family Name</th>
                    <th>Given Name</th>
                    <th>City</th>
                    <th>Phone</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>501</td>
                    <td>Skolik</td>
                    <td>Alexandra</td>
                    <td>Horrheim</td>
                    <td>0160 93313135</td>
                    <td>alexandra.skolik@example.com</td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>