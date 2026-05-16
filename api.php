<?php
require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

cors_public_api_headers();

try {
    $conn = db_mysqli();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed', 'details' => $e->getMessage()]);
    exit();
}

// === 5. Handle GET ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $conn->query("SELECT * FROM app_dtl where status = 1");
    $data = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Query failed", "details" => $conn->error]);
    }

    $conn->close();
    exit();
}

// === 6. Handle POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawData = file_get_contents("php://input");
    file_put_contents("debug_raw.json", $rawData);

    $data = json_decode($rawData, true);
    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON", "raw" => $rawData]);
        exit();
    }

    // Extract first element of arrays if needed
    $gender = isset($data['gender'][0]) ? $data['gender'][0] : '';
    $state = isset($data['stateProvince'][0]) ? $data['stateProvince'][0] : '';
    $firstName = $conn->real_escape_string($data['firstName'] ?? '');
    $lastName = $conn->real_escape_string($data['lastName'] ?? '');
    $fatherName = $conn->real_escape_string($data['fatherName'] ?? '');
    $gender = $conn->real_escape_string($gender);
    $email = $conn->real_escape_string($data['email'] ?? '');
    $cnic = $conn->real_escape_string($data['cnic'] ?? '');
    $dob = $conn->real_escape_string($data['dob'] ?? '');
    $dob_date_plus_one = date('y-m-d', strtotime($dob . ' + 1 day'));
    $eduYear = $conn->real_escape_string($data['edupassyear'] ?? '');
    $phone = $conn->real_escape_string($data['phoneNumber'] ?? '');
    $address = $conn->real_escape_string($data['address'] ?? '');
    $state = $conn->real_escape_string($state);
    $city = $conn->real_escape_string($data['city'] ?? '');
    $postalCode = intval($data['postalCode'] ?? 0);
    $grade = $conn->real_escape_string($data['marks'] ?? '');
    $degree = $conn->real_escape_string($data['degree'] ?? '');
    $institute = $conn->real_escape_string($data['institute'] ?? '');
    $appId = intval($data['application'] ?? 0);
    $prevExp = intval($data['experience'] ?? 0);
    $companyName = $conn->real_escape_string($data['comName'] ?? '');
    $prevSalary = intval($data['prevSalary'] ?? 0);
    $newSalary = intval($data['newSalary'] ?? 0);
    $cvPath = $conn->real_escape_string($data['cv'] ?? '');
    $currentDesig = $conn->real_escape_string($data['currentDesig'] ?? '');
    $jobDesc = $conn->real_escape_string($data['jobDesc'] ?? '');


    // === Insert Query ===
    $sql = "INSERT INTO candidate_dtl (
        FIRST_NAME, LAST_NAME, FATHER_NAME, GENDER, EMAIL, CNIC, DOB, PHONE, ADDRESS,
        STATE, CITY, POSTAL_CODE, EDU_YEAR, GRADE, DEGREE, INSTITUTE, APP_ID,
        PREV_EXP, COMPANY_NAME, PREV_SALARY, NEW_SALARY, created_date, cv_path, job_desc, current_desig
    ) VALUES (
        '$firstName', '$lastName', '$fatherName', '$gender', '$email', '$cnic', '$dob_date_plus_one', '$phone', '$address',
        '$state', '$city', $postalCode, '$eduYear', '$grade', '$degree', '$institute', $appId,
        $prevExp, '$companyName', $prevSalary, $newSalary, NOW(), '$cvPath', '$jobDesc', '$currentDesig')";

    file_put_contents("debug_sql.txt", $sql);

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Data inserted successfully"]);

    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Insert failed",
            "sql" => $sql,
            "mysql_error" => $conn->error
        ]);
    }
    $conn->close();
    exit();
}

// === 7. Fallback for unsupported methods ===
http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);

