pid_file = "/vault/agent.pid"

# VAULT_ADDR comes from env so the same hcl works single-host (vault:8200) and
# cross-host (http://<gateway-ip>:8200), mirroring the feat/vault pattern.

auto_auth {
  method "approle" {
    config = {
      role_id_file_path                   = "/vault/creds/order-svc/role_id"
      secret_id_file_path                 = "/vault/creds/order-svc/secret_id"
      remove_secret_id_file_after_reading = false
    }
  }
  sink "file" {
    config = { path = "/vault/agent.token" }
  }
}

# Issue X.509 identity. Render cert+CA+key into ONE template (bundle.pem) and
# split into tls.crt/tls.key in the command hook. The split is guarded on the
# bundle containing a PRIVATE KEY block — consul-template's view cache drops
# .Key on token-renewal re-renders, so cache-hit renders write a bundle with
# no key block; we skip the split then, preserving the last fresh issue.
template {
  destination = "/vault/out/bundle.pem"
  perms       = "0600"
  command     = "sh -c \"grep -q 'PRIVATE KEY' /vault/out/bundle.pem && awk -v c=/vault/out/tls.crt -v k=/vault/out/tls.key 'BEGIN{m=c} /PRIVATE KEY/{m=k} {print > m}' /vault/out/bundle.pem && chmod 0644 /vault/out/tls.crt && chmod 0600 /vault/out/tls.key || true\""
  contents    = <<-EOT
  {{- with pkiCert "pki/issue/order-svc" "common_name=order-service.zt.local" "uri_sans=spiffe://zt.local/order-service" "ttl=24h" -}}
  {{ .Cert }}
  {{ .CA }}
  {{ .Key }}
  {{- end -}}
  EOT
}

# Trust bundle (stable issuing CA) for verifying peers.
template {
  destination = "/vault/out/ca.crt"
  contents    = "{{ with secret \"pki/cert/ca\" }}{{ .Data.certificate }}{{ end }}"
}
