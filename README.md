# PPL MyApi2 client in PHP

PPL (Professional Parcel Logistic) recently launched a new version of their API called Create package label or MyApi2.

This package allows you to connect to the API by simply providing your credentials without the need to set up OAuth.

Further, it offers some helper functions for encoding requests or decoding responses.
Optionally, it can cache the token across requests when [a class implementing Psr\SimpleCache\CacheInterface](https://packagist.org/providers/psr/simple-cache-implementation) is passed. This is highly recommended since the PPL quota for requesting new tokens (12 per minute) can be easily exceeded.

## Requirements
- PHP 7.4 or higher

## Installation
```shell
$ composer require szymsza/ppl-create-package-label-api
```

## Credentials
You must request your credentials and the API documentation from PPL support. Klient.ppl.cz credentials will **not** work.

## Usage
The library's primary purpose is being only an OAuth wrapper dealing with authentication. Therefore, for most endpoints you will need to send raw requests and parse the received data yourself.

However, a simple example covering frequent use cases can be found in `example/example.php`. After filling in your credentials, the script should work without any further configuration. After consulting this example script and the API documentation, using other endpoints should be intuitive.

### Initializing the client
```php
$clientId = 'XXX';
$clientSecret = 'YYY';
$development = true;
$cache = new SomeCacheImplementingPsr16();  // optional
$ppl = new PPL($clientId, $clientSecret, $development, $cache);
```

### Basic connection
Call the API to get basic information, such as the API version or the current timee. Useful to test your connection.
```php
var_dump($ppl->versionInformation());
```

Call the API to get Swaggger JSON describing the available API endpoints. You can view this JSON by pasting it, e.g., to [https://editor.swagger.io/](https://editor.swagger.io/).
```php
var_dump($ppl->getSwagger());
```

### Request methods
The class offers three methods to initiate requests:
- `requestJson(string $path, string $method = 'get', array $data = []): array|object|null` initiates the request and returns the response as a decoded JSON array or object. This is useful in case the response of the endpoint is JSON and you do not care about the received headers.
- `requestHeader(string $path, string $method = 'get', array $data = [], string $header = 'Location'): string` initiates the request and returns the value of a single header from the response. This is useful in case you do not care about the response body or values of other headers.
- `request(string $path, string $method = 'get', array $data = [])` initiates the request and returns the raw `ResponseInterface` object. This is useful in case you cannot use either of the methods above, e.g., if you need both the response body and the headers.

For examply, you can use `requestJson` to fetch available products like this:
```php
var_dump($ppl->requestJson('codelist/product?limit=50&offset=0'));
```

### Creating a shipment label
```php
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

// Wait for the label to be created on the server
// Note - you probably want to use some smarter solution on production...
do {
    sleep(1);

    // Get the batch result
    $batchStatus = $ppl->requestJson($batchUrl);
} while ($batchStatus->items[0]->importState !== "Complete");

// Save the two types of labels for the first shipment
$bigLabelUrl = $ppl->relativizeUrl($batchStatus->completeLabel->labelUrls[0]);
$singleLabelUrl = $ppl->relativizeUrl($batchStatus->items[0]->labelUrl);

file_put_contents("big.pdf", $ppl->request($bigLabelUrl)->getBody()->getContents());
file_put_contents("single.pdf", $ppl->request($singleLabelUrl)->getBody()->getContents());
```
For using other endpoints consult the documentation and pick one of the adequate `request` methods mentioned above.