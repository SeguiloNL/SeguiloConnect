<?php
// lib/Vendors/OrderPayloadMapper.php

function buildVendorOrderPayload(array $order, array $orderLines, array $settings): array {
  // Voorbeeld payload — vervang op basis van de leverancier-specificaties
  $items = [];
  foreach ($orderLines as $line) {
    $items[] = [
      'Sku'        => $line['sku'] ?? null,
      'Quantity'   => (int)($line['qty'] ?? 1),
      'ICCID'      => $line['iccid'] ?? null,   // indien SIM activatie
      'IMSI'       => $line['imsi'] ?? null,    // indien nodig
      'PlanId'     => $line['plan_id'] ?? null, // bundel/abonnement
      'MSISDN'     => $line['msisdn'] ?? null,  // telefoonnummer
    ];
  }

  $payload = [
    'AccountId'        => $settings['account_id'] ?? null,      // vaak verplicht
    'ExternalOrderId'  => (string)$order['id'],                  // jouw order-id als referentie
    'Customer' => [
      'Company'   => $order['company_name'] ?? null,
      'FirstName' => $order['first_name'] ?? null,
      'LastName'  => $order['last_name'] ?? null,
      'Email'     => $order['email'] ?? null,
      'Phone'     => $order['phone'] ?? null,
      'Address'   => [
        'Line1'   => $order['address_line1'] ?? null,
        'Line2'   => $order['address_line2'] ?? null,
        'City'    => $order['city'] ?? null,
        'Postcode'=> $order['postcode'] ?? null,
        'Country' => $order['country'] ?? 'NL',
      ],
    ],
    'Items' => $items,
    // Extra activatie-opties:
    'Activation' => [
      'ActivateNow' => true,
      // 'ActivationDate' => '2025-09-03T10:00:00Z'
    ],
  ];

  // Verwijder nulls netjes:
  $payload = array_filter($payload, function($v){ return !is_null($v); });
  return $payload;
}