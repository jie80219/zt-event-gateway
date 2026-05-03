# Rendered by Vault Agent. Do not edit by hand.

CI_ENVIRONMENT = production
{{ with secret "secret/data/zt-event-gateway/db/order" }}
database.default.hostname = {{ .Data.data.host }}
database.default.database = {{ .Data.data.db }}
database.default.username = {{ .Data.data.user }}
database.default.password = {{ .Data.data.pass }}
database.default.DBDriver = Postgre
database.default.DBPrefix =
database.default.port     = {{ .Data.data.port }}
{{ end }}
