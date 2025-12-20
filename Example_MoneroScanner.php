<?php

// Example usage of MoneroScanner class
require_once 'Class_MoneroScanner.php';
$scanner = new MoneroScanner('mainnet');

// Step 1: Fetch block data
$rpc_url = 'http://node.example.com:18081'; // <-- Replace with your node rpc url
$socks5_proxy = '127.0.0.1:9050'; // <-- It is recommended to use a proxy. Can also be null
$block = $scanner->get_block_by_height(1234567, $rpc_url, $socks5_proxy);
echo "Fetching block...\n";
if (isset($block['error'])) exit("Error: " . $block['error']);
echo "Block hash: {$block['hash']} TX count: {$block['tx_count']} Timestamp: {$block['timestamp']}\n";

// Step 2: Extract candidate transactions
echo "Extracting candidate transactions...\n";
$my_private_view_key = '7c0edd...a51277'; // <-- Replace with your private view key (64 characters hex)
$txs = $scanner->extract_transactions_to_me($block['transactions'], $my_private_view_key);

// Step 3: Verify and process transactions
foreach ($txs as $tx) {

    // Verify public spend key matches one of your subaddresses key (Important)
    if (!is_my_subaddress($tx['public_spend_key'])) {
        echo "Transaction verification failed.\n";
        continue; // Irrelevant transaction
    }

    // Process verified transaction here
    echo "Transaction verified:\n";
    var_dump($tx);

}

// A function that checks whether a public spend key belongs to one of your subaddresses
function is_my_subaddress(string $public_spend_key): bool {
    static $my_public_spend_keys = [
        'a6b40c57...ef59023a', // <-- Replace with your public spend keys (64 characters hex)
        'e490fac1...9ed468a0', // <-- Replace with your public spend keys (64 characters hex)
    ];
    return in_array($public_spend_key, $my_public_spend_keys);
}
