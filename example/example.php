<?php

use Szymsza\PhpPplCreatePackageLabelApi\PPL;

require_once '../vendor/autoload.php';

$clientId = 'XXX';
$clientSecret = 'YYY';
// $cache = new SomeCacheImplementingPsr16();   // optionally provide an instance of your favourite PSR-16-compliant cache
$ppl = new PPL($clientId, $clientSecret, true, /* $cache */);

// Calls the API to get basic information, such as the API version or the current timee. Useful to test your connection.
echo "<h2>Basic information</h2>";
echo "<pre>";
var_dump($ppl->versionInformation());
echo "</pre>";

// Calls the API to get Swaggger JSON describing the available API endpoint. You can view this JSON by pasting it, e.g., to https://editor.swagger.io/
echo "<h2>Swagger documentation</h2>";
$swagger = $ppl->getSwagger();
echo "<small>" . substr($swagger, 0, 1000) . "</small><br><b>... modify the source file to see the full content</b>";

// Display available products
echo "<h2>Available products</h2>";
echo "<pre>";
var_dump($ppl->requestJson('codelist/product?limit=50&offset=0'));
echo "</pre>";

// Create a new shipment label
echo "<h2>Creating new label</h2>";
// Initialize the label batch
$batchUrl = $ppl->requestHeader('shipment/batch', 'post', [
    "labelSettings" => [
        "format" => "Pdf",
        "completeLabelSettings" => [
            "isCompleteLabelRequested" => true,
            "pageSize" => "A4"
        ]
    ],
    "shipments" => [
        [
            "referenceId" => "fe125c3a-3a36-487b-9e2b-e8919910ff63",
            "productType" => "BUSS",
            "sender" => [
                "street" => "Novoveská 1262/95",
                "city" => "Ostrava",
                "zipCode" => "70900",
                "country" => "CZ",
                "phone" => "+420777888999",
                "email" => "sender@email.cz"
            ],
            "recipient" => [
                "street" => "Františka a Anny Ryšových 1168",
                "city" => "Ostrava-Svinov",
                "zipCode" => "72100",
                "country" => "CZ",
                "phone" => "+420666777888",
                "email" => "recipient@email.cz"
            ]
        ]
    ],
    "shipmentsOrderBy" => "ShipmentNumber"
]);

echo "Batch job created: " . $batchUrl . "<br>";
echo "Waiting for label creation...";

// Wait for the label to be created on the server
// Note - you probably want to use some smarter solution on production...
do {
    sleep(1);

    // Get the batch result
    $batchStatus = $ppl->requestJson($batchUrl);
} while ($batchStatus->items[0]->importState !== "Complete");

echo "<h3>Label data received</h3>";
echo "<pre>";
var_dump($batchStatus);
echo "</pre>";

// Download two types of labels for the first shipment
$bigLabelUrl = $ppl->relativizeUrl($batchStatus->completeLabel->labelUrls[0]);
$singleLabelUrl = $ppl->relativizeUrl($batchStatus->items[0]->labelUrl);

echo "Download URLs obtained: <br>";
echo "&nbsp;&nbsp;" . $bigLabelUrl . "<br>";
echo "&nbsp;&nbsp;" . $singleLabelUrl . "<br>";

file_put_contents(__DIR__ . "/big.pdf", $ppl->request($bigLabelUrl)->getBody()->getContents());
file_put_contents(__DIR__ . "/single.pdf", $ppl->request($singleLabelUrl)->getBody()->getContents());

echo "<h3>Labels saved</h3>";
echo "<b>Labes saved to big.pdf and single.pdf in the " . __DIR__ . " directory.</b>";