pid_file        = "/vault/agent.pid"
exit_after_auth = false

# Vault address comes from VAULT_ADDR env in the per-host docker-compose so
# the same hcl works on the gateway host (vault:8200 docker DNS) and on the
# remote CI4 service hosts (10.1.1.209:8200 LAN).

template_config {
  static_secret_render_interval = "10s"
}

auto_auth {
  method "approle" {
    config = {
      role_id_file_path                   = "/vault/creds/production-svc/role_id"
      secret_id_file_path                 = "/vault/creds/production-svc/secret_id"
      remove_secret_id_file_after_reading = false
    }
  }

  sink "file" {
    config = {
      path = "/vault/agent.token"
    }
  }
}

template {
  source      = "/vault/templates/production-ci4.env.tpl"
  destination = "/vault/out/.env"
  perms       = "0640"
}
