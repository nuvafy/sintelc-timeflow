# Enrutamiento de protocolos biométricos

La selección de protocolo está centralizada en `config/biometric-protocols.php` y
se resuelve mediante `DeviceProtocolResolver`. No deben agregarse condiciones de
modelo directamente en controladores o componentes Livewire.

## Señales de detección

1. Perfil fijado manualmente (`push_protocol_source = manual`).
2. Coincidencia por `device_name` reportado durante el handshake.
3. Coincidencia por prefijo de firmware recibido en el parámetro `INFO`.
4. Perfil predeterminado `attendance_push`.

Cada llamada `GET /iclock/getrequest?SN=...&INFO=...` actualiza firmware,
contadores, fecha de telemetría y perfil detectado.

## Modos de inventario

- `detailed`: la consulta produce filas `USERINFO`/`user`; cada PIN confirma su
  asignación individualmente.
- `aggregate_info`: el dispositivo sólo expone un contador confiable en `INFO`.
  Un lote se confirma únicamente si todos sus comandos fueron aceptados, el
  `INFO` es posterior a los ACK y el contador alcanza la línea base más las
  altas del lote. La auditoría registra `device_info_count` como método.

Para incorporar un modelo nuevo, agregar sus firmas y capacidades al archivo de
configuración y cubrirlas con una prueba de `DeviceProtocolResolver` o
`DeviceInfoService`.
