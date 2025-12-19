<?php
/**
 * Example usage of MoneroKeyDerivation class
 *
 * This example shows how to derive keys from mnemonic and generate subaddresses.
 */

require_once 'Class_MoneroKeyDerivation.php';

// Minimal example mnemonic (replace with your own 25-word mnemonic)
$mnemonic = 'YOUR_25_WORD_MNEMONIC_HERE';

$key_derivation = new MoneroKeyDerivation();

echo "=== Monero Block Scanner PHP (Key Derivation Example) ===\n\n";

// 1. Derive keys from mnemonic
echo "1. Deriving keys from mnemonic...\n";
$keys = $key_derivation->derive_keys_from_mnemonic($mnemonic);

if (isset($keys['error'])) {
    echo "ERROR: " . $keys['error'] . "\n";
    exit(1);
}

echo "Public Spend Key:  " . $keys['public_spend_key'] . "\n";
echo "Public View Key:   " . $keys['public_view_key'] . "\n\n";

// 2. Generate main address
echo "2. Generating main address...\n";
$main_address = $key_derivation->get_main_address($mnemonic);

if (isset($main_address['error'])) {
    echo "ERROR: " . $main_address['error'] . "\n";
} else {
    echo "Main Address: " . $main_address['address'] . "\n";
    echo "Public Spend Key: " . $main_address['public_spend_key'] . "\n\n";
}

// 3. Generate some subaddresses
echo "3. Generating subaddresses...\n";
$subaddresses = $key_derivation->generate_subaddresses($mnemonic, 0, 3);

if (isset($subaddresses['error'])) {
    echo "ERROR: " . $subaddresses['error'] . "\n";
} else {
    foreach ($subaddresses as $i => $subaddr) {
        echo "Subaddress $i: " . $subaddr['address'] . "\n";
        echo "  Public Spend Key: " . $subaddr['public_spend_key'] . "\n";
        echo "  Major Index: " . $subaddr['major_index'] . ", Minor Index: " . $subaddr['minor_index'] . "\n\n";
    }
}

// 4. Generate a specific subaddress
echo "4. Generating specific subaddress (account 1, subaddress 5)...\n";
$specific = $key_derivation->generate_subaddress($mnemonic, 1, 5);

if (isset($specific['error'])) {
    echo "ERROR: " . $specific['error'] . "\n";
} else {
    echo "Account 1, Subaddress 5: " . $specific['address'] . "\n";
    echo "Public Spend Key: " . $specific['public_spend_key'] . "\n";
}

echo "\n=== Usage with MoneroScanner ===\n";
echo "Use the derived private view key + the subaddress public spend keys with MoneroScanner.\n";

