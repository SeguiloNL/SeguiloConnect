<?php
// lib/Vendors/ApiControlCenterClient.php

class ApiControlCenterClient {
  private $pdo;
  private $settings; // row uit vendor_api_settings

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
    $this->settings = $this->getSettings();
    if (!$this->settings || !$this->settings['is_active']) {
      throw new RuntimeException('Leverancier-API is niet geconfigureerd of inactief.');
    }
  }

  private function getSettings(): ?array {
    $stmt = $this->pdo->prepare("SELECT * FROM vendor_api_settings WHERE name = 'apicontrolcenter' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  private function getAuthHeader(): array {
    switch ($this->settings['auth_type']) {
      case 'bearer_token':
        if (empty($this->settings['bearer_token'])) throw new RuntimeException('Bearer token ontbreekt.');
        return ['Authorization: Bearer '.$this->settings['bearer_token']];

      case 'api_key':
        if (empty($this->settings['api_key'])) throw new RuntimeException('API key ontbreekt.');
        // Vaak is dit 'x-api-key: {key}' of 'Authorization: ApiKey {key}'
        return ['x-api-key: '.$this->settings['api_key']];

      case 'oauth2':
        // Eenvoudige token-cache demo: je kunt token opslaan in bearer_token veld
        if (empty($this->settings['bearer_token'])) {
          $this->refreshOAuthToken();
        }
        return ['Authorization: Bearer '.$this->settings['bearer_token']];

      default:
        throw new RuntimeException('Onbekende auth_type.');
    }
  }

  private function refreshOAuthToken(): void {
    if (empty($this->settings['oauth_token_endpoint']) || empty($this->settings['oauth_client_id']) || empty($this->settings['oauth_client_secret'])) {
      throw new RuntimeException('OAuth2 instellingen onvolledig.');
    }
    // TODO: pas grant_type en body aan wat de leverancier vraagt
    $payload = http_build_query([
      'grant_type' => 'client_credentials',
      'client_id' => $this->settings['oauth_client_id'],
      'client_secret' => $this->settings['oauth_client_secret'],
      'scope' => '' // indien nodig
    ]);

    $ch = curl_init($this->settings['oauth_token_endpoint']);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    $res = curl_exec($ch);
    if ($res === false) throw new RuntimeException('OAuth token request failed: '.curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    if ($code >= 400 || empty($json['access_token'])) {
      throw new RuntimeException('OAuth token mislukte (HTTP '.$code.'): '.$res);
    }

    // Sla token op voor hergebruik
    $stmt = $this->pdo->prepare("UPDATE vendor_api_settings SET bearer_token = :tkn, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':tkn' => $json['access_token'], ':id' => $this->settings['id']]);
    $this->settings['bearer_token'] = $json['access_token'];
  }

  private function request(string $method, string $path, array $body = null, array $query = []): array {
    $url = rtrim($this->settings['base_url'], '/').$path;
    if ($query) $url .= '?'.http_build_query($query);

    $headers = array_merge(
      ['Accept: application/json'],
      $this->getAuthHeader(),
      $body ? ['Content-Type: application/json'] : []
    );

    $ch = curl_init($url);
    $opts = [
      CURLOPT_CUSTOMREQUEST => strtoupper($method),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
      $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    if ($raw === false) {
      $err = curl_error($ch);
      curl_close($ch);
      throw new RuntimeException("HTTP fout: $err");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    return ['status' => $status, 'json' => $json, 'raw' => $raw];
  }

  /* =========================
     Publieke methods (aanpassen aan leverancier)
     ========================= */

  // 3a) Order bij leverancier aanmaken of activeren (pas endpoint/payload aan)
  public function activateOrder(array $payload): array {
    // TODO: check de exacte path/velden in de Swagger van apicontrolcenter.
    // Veelvoorkomend patroon:
    //  - POST /v3/orders/activate  of  /v3/orders  of /v3/activations
    //  - vereiste velden: AccountId, ExternalOrderId, Items[], SIM/ICCID/IMSI, PlanId, MSISDN, etc.
    $path = '/v3/orders'; // zet dit op het juiste pad!
    return $this->request('POST', $path, $payload);
  }

  // 3b) Order-status ophalen op vendor-id
  public function getOrderStatus(string $vendorOrderId): array {
    // TODO: exact path controleren
    $path = '/v3/orders/'.$vendorOrderId;
    return $this->request('GET', $path);
  }
}