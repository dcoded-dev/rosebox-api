<?php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to make a cURL request
function makeCurlRequest($method, $url, $auth, $data = null, &$debugInfo) {
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_USERPWD, $auth['username'] . ':' . $auth['password']);

    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $verbose = fopen('php://temp', 'w+');
    curl_setopt($curl, CURLOPT_VERBOSE, true);
    curl_setopt($curl, CURLOPT_STDERR, $verbose);

    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);

    curl_close($curl);

    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    fclose($verbose);

    // Store debug information
    $debugInfo['verboseLog'] = $verboseLog;
    $debugInfo['httpStatus'] = $status;
    $debugInfo['curlError'] = $curlError;

    return [
        'response' => $response,
        'status' => $status,
        'error' => $curlError
    ];
}

// Function to process the API response and post the first order
function processAndPostFirstOrder($get_response, $auth, &$debugInfo) {
    if ($get_response['response'] === false || $get_response['status'] != 200) {
        $debugInfo['GET'] = "GET request failed. HTTP Status: " . $get_response['status'] . ". cURL Error: " . $get_response['error'];
        return ['error' => $debugInfo['GET']];
    }

    $responseArray = json_decode($get_response['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $debugInfo['GET'] = 'JSON decoding error: ' . json_last_error_msg();
        return ['error' => $debugInfo['GET']];
    }

    if (isset($responseArray['orders']) && count($responseArray['orders']) > 0) {
        $firstOrder = $responseArray['orders'][0];

        // Extract dates from customerNotes
        $shippingDate = '';
        $pickupDate = '';
        if (preg_match("/Shipping-Date: (\d{4}\/\d{2}\/\d{2})/", $firstOrder['customerNotes'], $matchesShipping)) {
            $shippingDate = $matchesShipping[1];
        }
        if (preg_match("/Pickup-Date: (\d{4}\/\d{2}\/\d{2})/", $firstOrder['customerNotes'], $matchesPickup)) {
            $pickupDate = $matchesPickup[1];
        }

        // Determine which date to use
        $dateToUse = $shippingDate ?: $pickupDate;

        // Set the customField3 value only if a date is found
        if (!empty($dateToUse)) {
            if (!isset($firstOrder['advancedOptions'])) {
                $firstOrder['advancedOptions'] = []; // Ensure advancedOptions exists if not already
            }
            $firstOrder['advancedOptions']['customField3'] = $dateToUse; // Assign the date to customField3
        }

        $postUrl = "https://ssapi.shipstation.com/orders/createorder/" . $firstOrder['orderId'];
        $postResponse = makeCurlRequest('POST', $postUrl, $auth, $firstOrder, $debugInfo['POST']);

        if ($postResponse['response'] === false || $postResponse['status'] != 200) {
            return ['error' => "POST request failed. HTTP Status: " . $postResponse['status'] . ". cURL Error: " . $postResponse['error']];
        }

        return json_decode($postResponse['response'], true); // Return decoded POST response
    }

    $debugInfo['GET'] = 'No orders found in the GET response.';
    return ['error' => $debugInfo['GET']];
}

// Main logic
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);
$debugInfo = [];

if (isset($data['resource_url'])) {
    $auth = [
        'username' => 'f8ffecb9734744618b22e6d396f6b926', // Your actual username
        'password' => '10e00f8f195346bbb15cac85e20fbea9'  // Your actual password
    ];
    $getResponse = makeCurlRequest('GET', $data['resource_url'], $auth, null, $debugInfo['GET']);

    $postResult = processAndPostFirstOrder($getResponse, $auth, $debugInfo);

    header('Content-Type: application/json');
    echo json_encode(['result' => $postResult, 'debugInfo' => $debugInfo]);
} else {
    echo json_encode(['error' => 'No resource_url provided.']);
}
