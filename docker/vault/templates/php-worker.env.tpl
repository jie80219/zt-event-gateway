{{- with secret "secret/data/zt-event-gateway/rabbitmq" -}}
RABBITMQ_USER={{ .Data.data.user }}
RABBITMQ_PASS={{ .Data.data.pass }}
AMQP_USER={{ .Data.data.user }}
AMQP_PASSWORD={{ .Data.data.pass }}
{{- end }}
