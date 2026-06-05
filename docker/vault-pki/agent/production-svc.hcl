pid_file = "/vault/agent.pid"

# VAULT_ADDR comes from env so the same hcl works single-host (vault:8200) and
# cross-host (http://<gateway-ip>:8200), mirroring the feat/vault pattern.

auto_auth {
  method "approle" {
    config = {
      role_id_file_path                   = "/vault/creds/production-svc/role_id"
      secret_id_file_path                 = "/vault/creds/production-svc/secret_id"
      remove_secret_id_file_after_reading = false
    }
  }
  sink "file" {
    config = { path = "/vault/agent.token" }
  }
}

# Issue this service's X.509 identity (SPIFFE-style URI SAN) and renew before
# expiry. cert+CA → tls.crt, private key → tls.key (kept in sync per issuance).
template {
  destination = "/vault/out/tls.crt"
  contents    = <<-EOT
  {{- with pkiCert "pki/issue/production-svc" "common_name=production-service.zt.local" "uri_sans=spiffe://zt.local/production-service" "ttl=24h" -}}
  {{ .Cert }}{{ .CA }}
  {{ .Key | writeToFile "/vault/out/tls.key" "" "" "0600" }}
  {{- end -}}
  EOT
}

# Trust bundle (stable issuing CA) for verifying peers.
template {
  destination = "/vault/out/ca.crt"
  contents    = "{{ with secret \"pki/cert/ca\" }}{{ .Data.certificate }}{{ end }}"
}
