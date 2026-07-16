<?php
require_once '../../config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation - <?php echo SITE_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f8f9fa;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin: -2rem -2rem 2rem -2rem;
            text-align: center;
        }
        
        .nav {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            position: sticky;
            top: 2rem;
            z-index: 100;
        }
        
        .nav ul {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .nav a {
            color: #667eea;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .nav a:hover {
            background: #f0f2ff;
        }
        
        .section {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .section h2 {
            color: #667eea;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f2ff;
        }
        
        .endpoint {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        
        .method {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.875rem;
            margin-right: 1rem;
        }
        
        .method.get { background: #d4edda; color: #155724; }
        .method.post { background: #d1ecf1; color: #0c5460; }
        .method.put { background: #fff3cd; color: #856404; }
        .method.delete { background: #f8d7da; color: #721c24; }
        
        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            margin: 1rem 0;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 0.875rem;
        }
        
        .params-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        .params-table th,
        .params-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .params-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .required {
            color: #dc3545;
            font-weight: bold;
        }
        
        .optional {
            color: #6c757d;
        }
        
        .response-example {
            background: #f8f9fa;
            border-left: 4px solid #28a745;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .error-example {
            background: #f8f9fa;
            border-left: 4px solid #dc3545;
            padding: 1rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo SITE_NAME; ?> API Documentation</h1>
            <p>Integrate data bundle services into your application</p>
        </div>

        <nav class="nav">
            <ul>
                <li><a href="#overview">Overview</a></li>
                <li><a href="#authentication">Authentication</a></li>
                <li><a href="#rate-limits">Rate Limits</a></li>
                <li><a href="#endpoints">Endpoints</a></li>
                <li><a href="#errors">Error Handling</a></li>
                <li><a href="#examples">Examples</a></li>
            </ul>
        </nav>

        <section id="overview" class="section">
            <h2>Overview</h2>
            <p>The <?php echo SITE_NAME; ?> API allows approved agents to integrate data bundle purchasing capabilities into their applications. This RESTful API provides endpoints for checking balances, browsing packages, and purchasing data bundles programmatically.</p>
            
            <h3>Base URL</h3>
            <div class="code-block"><?php echo SITE_URL; ?>/api/reseller/</div>
            
            <h3>Content Type</h3>
            <p>All requests and responses use JSON format. Include the following header:</p>
            <div class="code-block">Content-Type: application/json</div>
        </section>

        <section id="authentication" class="section">
            <h2>Authentication</h2>
            <p>API access requires a valid API key obtained through the agent dashboard after admin approval.</p>
            
            <h3>API Key Header</h3>
            <div class="code-block">X-API-Key: your_api_key_here</div>
            
            <h3>Alternative: Bearer Token</h3>
            <div class="code-block">Authorization: Bearer your_api_key_here</div>
            
            <h3>Getting API Access</h3>
            <ol>
                <li>Log into your agent dashboard</li>
                <li>Navigate to "API Access"</li>
                <li>Submit an application with your use case</li>
                <li>Wait for admin approval</li>
                <li>Generate API keys once approved</li>
            </ol>
        </section>

        <section id="rate-limits" class="section">
            <h2>Rate Limits</h2>
            <p>API requests are rate limited to ensure fair usage:</p>
            
            <table class="params-table">
                <thead>
                    <tr>
                        <th>Time Window</th>
                        <th>Default Limit</th>
                        <th>Header</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Per Minute</td>
                        <td>60 requests</td>
                        <td>X-RateLimit-Minute</td>
                    </tr>
                    <tr>
                        <td>Per Hour</td>
                        <td>1,000 requests</td>
                        <td>X-RateLimit-Hour</td>
                    </tr>
                    <tr>
                        <td>Per Day</td>
                        <td>10,000 requests</td>
                        <td>X-RateLimit-Day</td>
                    </tr>
                </tbody>
            </table>
            
            <p>Rate limits may be adjusted based on your approved volume tier. When limits are exceeded, you'll receive a <code>429 Too Many Requests</code> response with a <code>Retry-After</code> header.</p>
        </section>

        <section id="endpoints" class="section">
            <h2>API Endpoints</h2>

            <div class="endpoint">
                <h3><span class="method get">GET</span>/balance</h3>
                <p>Get your current wallet balance and account information.</p>
                
                <h4>Response</h4>
                <div class="response-example">
                    <div class="code-block">{
  "success": true,
  "data": {
    "balance": 150.75,
    "currency": "GHS",
    "agent_name": "John Doe"
  }
}</div>
                </div>
            </div>

            <div class="endpoint">
                <h3><span class="method get">GET</span>/networks</h3>
                <p>Get list of available mobile networks.</p>
                
                <h4>Response</h4>
                <div class="response-example">
                    <div class="code-block">{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "MTN",
      "code": "MTN",
      "color": "#ffcc00"
    },
    {
      "id": 2,
      "name": "AT",
      "code": "AT",
      "color": "#ff6600"
    }
  ]
}</div>
                </div>
            </div>

            <div class="endpoint">
                <h3><span class="method get">GET</span>/packages</h3>
                <p>Get available data packages, optionally filtered by network.</p>
                
                <h4>Query Parameters</h4>
                <table class="params-table">
                    <thead>
                        <tr>
                            <th>Parameter</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>network_id</td>
                            <td>integer</td>
                            <td class="optional">Optional</td>
                            <td>Filter packages by network ID</td>
                        </tr>
                    </tbody>
                </table>
                
                <h4>Response</h4>
                <div class="response-example">
                    <div class="code-block">{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "1GB - 1 Day",
      "volume": "1GB",
      "validity": "1 Day",
      "price": 3.00,
      "agent_price": 2.70,
      "network_name": "MTN",
      "network_code": "MTN"
    }
  ]
}</div>
                </div>
            </div>

            <div class="endpoint">
                <h3><span class="method post">POST</span>/purchase</h3>
                <p>Purchase a data bundle for a phone number.</p>
                
                <h4>Request Body</h4>
                <table class="params-table">
                    <thead>
                        <tr>
                            <th>Parameter</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>package_id</td>
                            <td>integer</td>
                            <td class="required">Required</td>
                            <td>ID of the data package to purchase</td>
                        </tr>
                        <tr>
                            <td>phone_number</td>
                            <td>string</td>
                            <td class="required">Required</td>
                            <td>Recipient phone number (with country code)</td>
                        </tr>
                        <tr>
                            <td>reference</td>
                            <td>string</td>
                            <td class="optional">Optional</td>
                            <td>Your custom reference for this transaction</td>
                        </tr>
                    </tbody>
                </table>
                
                <h4>Example Request</h4>
                <div class="code-block">{
  "package_id": 1,
  "phone_number": "+233241234567",
  "reference": "ORDER_12345"
}</div>
                
                <h4>Success Response</h4>
                <div class="response-example">
                    <div class="code-block">{
  "success": true,
  "data": {
    "order_id": 123,
    "reference": "ORDER_12345",
    "package": "1GB - 1 Day",
    "network": "MTN",
    "phone_number": "+233241234567",
    "amount": 2.70,
    "status": "success",
    "message": "Bundle purchase successful"
  }
}</div>
                </div>
                
                <h4>Error Response</h4>
                <div class="error-example">
                    <div class="code-block">{
  "success": false,
  "error": "Insufficient balance",
  "required": 2.70,
  "available": 1.50
}</div>
                </div>
            </div>

            <div class="endpoint">
                <h3><span class="method get">GET</span>/transactions</h3>
                <p>Get your transaction history.</p>
                
                <h4>Query Parameters</h4>
                <table class="params-table">
                    <thead>
                        <tr>
                            <th>Parameter</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>limit</td>
                            <td>integer</td>
                            <td class="optional">Optional</td>
                            <td>Number of records (max 100, default 50)</td>
                        </tr>
                        <tr>
                            <td>offset</td>
                            <td>integer</td>
                            <td class="optional">Optional</td>
                            <td>Number of records to skip (default 0)</td>
                        </tr>
                        <tr>
                            <td>status</td>
                            <td>string</td>
                            <td class="optional">Optional</td>
                            <td>Filter by status: success, pending, failed</td>
                        </tr>
                    </tbody>
                </table>
                
                <h4>Response</h4>
                <div class="response-example">
                    <div class="code-block">{
  "success": true,
  "data": [
    {
      "id": 123,
      "reference": "ORDER_12345",
      "phone_number": "+233241234567",
      "amount": 2.70,
      "status": "success",
      "created_at": "2025-08-25 12:00:00",
      "package_name": "1GB - 1 Day",
      "network_name": "MTN"
    }
  ],
  "pagination": {
    "limit": 50,
    "offset": 0
  }
}</div>
                </div>
            </div>
        </section>

        <section id="errors" class="section">
            <h2>Error Handling</h2>
            <p>The API uses standard HTTP status codes to indicate success or failure:</p>
            
            <table class="params-table">
                <thead>
                    <tr>
                        <th>Status Code</th>
                        <th>Meaning</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>200</td>
                        <td>OK</td>
                        <td>Request successful</td>
                    </tr>
                    <tr>
                        <td>400</td>
                        <td>Bad Request</td>
                        <td>Invalid request parameters</td>
                    </tr>
                    <tr>
                        <td>401</td>
                        <td>Unauthorized</td>
                        <td>Invalid or missing API key</td>
                    </tr>
                    <tr>
                        <td>404</td>
                        <td>Not Found</td>
                        <td>Endpoint or resource not found</td>
                    </tr>
                    <tr>
                        <td>429</td>
                        <td>Too Many Requests</td>
                        <td>Rate limit exceeded</td>
                    </tr>
                    <tr>
                        <td>500</td>
                        <td>Internal Server Error</td>
                        <td>Server error occurred</td>
                    </tr>
                </tbody>
            </table>
            
            <h3>Error Response Format</h3>
            <div class="error-example">
                <div class="code-block">{
  "success": false,
  "error": "Description of the error"
}</div>
            </div>
        </section>

        <section id="examples" class="section">
            <h2>Code Examples</h2>
            
            <h3>PHP Example</h3>
            <div class="code-block"><?php echo htmlspecialchars('<?php
$api_key = "your_api_key_here";
$base_url = "' . SITE_URL . '/api/reseller";

// Get balance
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $base_url . "/balance");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-API-Key: " . $api_key,
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
$data = json_decode($response, true);

if ($data["success"]) {
    echo "Balance: " . $data["data"]["balance"];
} else {
    echo "Error: " . $data["error"];
}

curl_close($ch);
?>'); ?></div>
            
            <h3>JavaScript Example</h3>
            <div class="code-block">const apiKey = 'your_api_key_here';
const baseUrl = '<?php echo SITE_URL; ?>/api/reseller';

// Purchase bundle
async function purchaseBundle(packageId, phoneNumber) {
    try {
        const response = await fetch(`${baseUrl}/purchase`, {
            method: 'POST',
            headers: {
                'X-API-Key': apiKey,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                package_id: packageId,
                phone_number: phoneNumber,
                reference: 'WEB_' + Date.now()
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            console.log('Purchase successful:', data.data);
        } else {
            console.error('Purchase failed:', data.error);
        }
    } catch (error) {
        console.error('Request failed:', error);
    }
}</div>
            
            <h3>Python Example</h3>
            <div class="code-block">import requests
import json

api_key = "your_api_key_here"
base_url = "<?php echo SITE_URL; ?>/api/reseller"

headers = {
    "X-API-Key": api_key,
    "Content-Type": "application/json"
}

# Get packages
response = requests.get(f"{base_url}/packages", headers=headers)
data = response.json()

if data["success"]:
    for package in data["data"]:
        print(f"{package['name']} - {package['network_name']} - GHS {package['agent_price']}")
else:
    print(f"Error: {data['error']}")</div>
        </section>

        <div class="section">
            <h2>Support</h2>
            <p>For API support, technical questions, or to report issues:</p>
            <ul>
                <li>Contact your account manager</li>
                <li>Email: support@<?php echo str_replace(['http://', 'https://'], '', SITE_URL); ?></li>
                <li>Check your agent dashboard for system status updates</li>
            </ul>
        </div>
    </div>
</body>
</html>
