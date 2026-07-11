START TRANSACTION;

-- Seed de direcciones default de clientes Odoo desde QA.
-- Mapea por nombre+telefono+fecha_creacion de clientes ya sembrados en produccion.

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AqHvlnaDT+lk1eQwRBLtBNK/rhn10hJu+dap2ZOpNITM', 'ENCv1:Aoo8/gBEqCPKZhpEgg/lu6PGa29nsMP8tjK0MFCWWBeoKjLIDhRIfp0x', 'ENCv1:AtdIJ4yrwD2rT4Z035GkKEGy4RHK0iRYhm33DQeHXVTMx+KWtx4BTPWaj7NXG8T48GDscnZ0AHaSCFAA+Z/bdmv/qUH4nw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AswenlmWjaRVayqcePl7SLO3mdKsL7iDVPhlru+4ji9atCFN'
  AND c.telefono = 'ENCv1:Aj15Q+a9HWGZciJbwbHBdujQ2xufZIThFIWKrTz+9K7/E34XGkiD'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AkdbanQAzUmLMMQ20GOh9mCXGIADYzhlhOKd97oyC4P8', 'ENCv1:Avt45GpCzazVTWbuoD+lkUEociE2NdSItP/VfS77JakmXKYmXeFAdjua', 'ENCv1:Aip+PoAZ+GSyh0ktz6l9hZalH59McV1KV7zB8Wj8PiQfrcAeq02ZdyuCOO2uGDK+kO2UeY7Qn7u//tr6EN2i9xj8WqOuZQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnYgW/vLnL2DWkSFxHuUAFRLjm+0YoZB0H80dNSD1eMoU1/fYpZkFxc='
  AND c.telefono = 'ENCv1:Apl+OFYHQ4s1mvuNbbHGUdl5ntt4uUu6ZJIXwbZZfcBofddMwc11'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Asb8WKlH54RpR/0+mwkSmKZuuy4QCan1trcYl9v7oerl', 'ENCv1:AoAR55nbW2ZA1tcE278ulXu5CtddjFY9i4KScvJKQWNicTAEMqafVyrs', 'ENCv1:AmC9o2AkWm8S+8wSO3CbSoCg9v1nsaKHNjQHCOtm7AHIVQod3byrQSNFVCviTKZ0ttGpJ+EDUcwTkQ+01KtMlx7barr14w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aq8vVgKtTxVVPlNUoA1xQztEwNGJyViA8LTCikHW1Hx5UsHkHYoZbHiQ'
  AND c.telefono = 'ENCv1:AvlhUgsK8NkRWoS0ti3JSoBeb9Mfo33ZbGCsQRQ5amjzk3aeX+F9'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ar+fTrtXufsy9l5zxTeJJlzNgP0D2bf+T0s5Bpx+5INq', 'ENCv1:Aoyiudb8YiTuNy07RHjjlZjZnF5WC4Ve3U7UqjKQGHPiT7KZlmFTZgef', 'ENCv1:AkNJ5/6ZceGYbrF/AI4IV6xVc3AXaqcxYLqQhdnWOXzH3tWllYdKp258/fBRQPiRuUajJjHbW5JRE4ln3iPDjhvf7tPDkr+UokI=', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ao8W3OTy/0c7ZEVz5IP1k4+G3uvweZwl9iffDiz6NvH8IWEwwHslVQ=='
  AND c.telefono = 'ENCv1:Ar2kqI7WPl5PaA8p7P8wcYvXITYymLRiZk6HUE3ZmSuuYOfvxxou'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AkrDKURkF1vtqCe6rfMCWrYtidIqhzHIqQ711dg30n/m', 'ENCv1:An72UQfsyH0gLjul19X828spDKcEaVDSIi/nRYTYG5ex8nxa4UbpZMDC', 'ENCv1:An9uZKcz3mDNSst8ZAqrN4yValGRhRbKBxLsZV+fWEqp9loAMuPLHqTTtBDXtUvH9EC8qdTiDHZ0MgA1POePXW4z6DXGD5YOLgHA8V3HNg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvovsaA0tucwVe2SmnJbSyTda1pJxkBF4vbQqbxNn1VN3L2sfi8='
  AND c.telefono = 'ENCv1:At8eYF/nKs+ONMn9cXNR8sS/AwySjXPeaxts+nkgGumaZd6sDom9'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvJrdIFIYS4c0HfwN/V0XGjCWL91wLbaQFxSXY+Jhoil', 'ENCv1:Atf7Yk1EiXLyh6SJott0oll+tmXUMYg0jRCZyKQUd3Cb52vASPpZ/DSq', 'ENCv1:Aim11hstPHFnSdBX53jASGnL9lFMLzPTbodAZp7YoX8l7+SHYtLtHdhKDD0v2pbnEVHWcOFWYIvlQJ4n2Z9vaRttHy4cyQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ar4j3gpcCqBC1COWNgYAtNq4sQQZ1gOu7bHaYOTy2DkEYbz1yHufkMfmnYo='
  AND c.telefono = 'ENCv1:AreXv0PlCTgc73Z0ydAUdHgwvZAM9R/w5zVRjUoyhWIPEbWTinyE'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Auw34YH/lvr2KyTzCol4JgCychTl6WyCVSXrnmNNbGOv', 'ENCv1:AoAuLuAisbXKdy5jABCMB78WAMtMNWNubeQfJ2jfK/Lkbf5chCAiHmzJ', 'ENCv1:AkoFlRlaiklZzPi0Kre9GZSwh893ONibVFAX3eE3jUxR39rGqlXLikahLk4zrMoTvXWHKRsiT05zDA0eEgGXmELB5fou2Q==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Av8wUIGoRZxJ9heysWOewftYIVrBLK9RmzlEcKPSfD49Xy4='
  AND c.telefono = 'ENCv1:AsYA49s2sruGsrw2NYCMVpnLRAmtX9SM8sQ+8A1ZOWq4k+m5nTY3'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmhUwqqqysG2K0WWBVmJvUmYricA0VB+b3XN5FGgxqDv', 'ENCv1:AkXvq49gCmMuPl3XvMBKeLoFpp91VBkr8pucMsedQ6w+lYc2qApcpEUF', 'ENCv1:AifVSEcN8W997YBZ+nAwDEMq7s19fC21f7+GKYjPv/WU4caa3hRikMVl3eQpdBUHeJbnXWMUNCdDrHzasrGFSi133o830A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApThn/jw/96cvq0/bcfbAJKBkXlOK6PkhkwqzVshQqoTwVOP4fR0v+RS8KHp7w=='
  AND c.telefono = 'ENCv1:AqIu/N169DuZvcaLMlofhqGDL04vGN+0T8N7qgWul4+ekMwqTYZj'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Al5WDxTY67WVajtNZKYftlqOzktQeFRD0IHkTz6BPtUd', 'ENCv1:ApGKqTfeXvsrnVIPs2slMOsyWSij3NyFshKf9ZH9+iO0OeiXz8p8lM01', 'ENCv1:AsNyc5qz8eYXevisuCGS88G1z9vottUnoE2TR0Ziw8wPCf2U9aQzxT2aFeQ7pg7gii8NNQWmiwCNTX/OpVxBa1SQSnvYAw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvC539u6nSkvYGaZCcydrqCfUn3/STZXqTyx11NRfVprL5BnrsL2Pc+9UwDx53PZkD8gJS9e'
  AND c.telefono = 'ENCv1:AnloYNgLK3+oq4+Hsf9y86X/zSQZnjRlOwcV6h2gLPIjFoZjLJ+1'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AhprQ75JaKE1wl0O6FwBcbvhJCASG38WY7BBeOYqV5ve', 'ENCv1:AhnmLMiYuJVFqGAuj/8SU3tNyYcX+44TjSGTsCtj7hY2pQhp3wz73Sx+', 'ENCv1:AsNOQT+zGDlm8hpw7myIo8eoVmovTLYbUNxOjdxkORocAqY8NTeeJwH+ciet7VtvK/+C4oWOqeP2VhqnTU5DlzV4GS44xg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ag8gL/ncu+Yy3GIezVbGoJtVOPJTjkKoNBIxElAtHRDt4S2fQaLlMbNHfoBjjGNiqRJQfQyXQSnu'
  AND c.telefono = 'ENCv1:AkoLqs23q2cZIgwzFwS7Xb04d47z+iJRPbGWf9XHCxmP7s6jUYLr'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AiBDb3CM32tugIYZh0GrLUTh7Bt+9g/XI48DQx6Dp8NP', 'ENCv1:AqsEYH2EFn28eHOdV68r7kfiANQfdEMWRXwB9o+3YMRlowwzBuVPDcta', 'ENCv1:AlT5LF26MOPZEc9aNuEqtJ0O9KR4MlTHGca2Kk2ikSdzpA2/KpVXdStRTol9TPeAsY0If3o8CZJhShvUs+C1OkG85GxNeg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AoKjvDpDs/t+LERddxvDTVRrXSUNE/06yWrl4bKXEsqkTFshiZsVNdJY0J4='
  AND c.telefono = 'ENCv1:ArUPjBQGz+UKnSRDrYLv+IhMWWWKnBYBKb6CyfvJcADHXaHUOPJU'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AjjgklXXdBxzPaYrRKKcomUj1sP0xTlQ51ZM5z0Vhw+r', 'ENCv1:AkbJuhONXXU6t0sBve8WwaXwHjf+3D4M+IgNlGFdZCpMKqLaqdn9eGJ5', 'ENCv1:AsiqHyFeFh/kzUvrlQIQ9uIwypsq9YGPQLtJzR3sSaGQcBYLvGVgXlFsTK7hlLB1htmjamRoUQXKEVSjcweb6jHJjT/K/g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AktK805pANkYuQNMzcg5128NARb5uMj6QGXkQXRWareyx/3z7jmqSw=='
  AND c.telefono = 'ENCv1:Ak9/eAyHsDii23DXNUmAaB+kjpHCPH2Pn4HJS5jbB4hYUVDZPWqh'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvcrrhngQwx1gbMSYzdqXDeT0zLRlVTJ05sGUIurw9gA', 'ENCv1:ArtPowbCeb7mjXdaW7WRoKUMeDsRu3Bw/gFkAFgOfUb5jHiSA3/nzvVZ', 'ENCv1:AscEkm2Mlk3jSGpQ7oE8H9KoRKb2SMm/aIsmb2EpsAgZIo9TXwLxNublOqq2HCeBg5rvd98/1YbvPhqApPW6pfsOnEQOhA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AhFEEdZYY4dFbKRCzIstoREGWRsV7y4AkaZvj5fRhEszgH/MF/09lcfBIOFQqA=='
  AND c.telefono = 'ENCv1:Am3DSOLHMPVCsgzEMduqfXj/EthSVwCTFp+5swoEYHcJ/MgHARmL'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Al+AKBBcKG3J3apWe2vpjnUPXJc5YB6ZxGdN6+ruqtIR', 'ENCv1:AgD9w75KDCsGZIHlyhe+Sc7wM3xcZ2R/CgVbhSvJF3E+LoQmWfRws6+k', 'ENCv1:AgWPMo6x3etgTdlMiUQ9jufNLLIu3FM8Fr/OQOuadx05Sje4ul2i4K/zdgDXI13ABgl33+06/CDvZjojeXbPTU8iQT/SXA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ao55drQ2s1I9BU5N5rD4IpqcfxgiyNLVAFJfa9kt+rxX'
  AND c.telefono = 'ENCv1:AisDJIeoRjV1ijyBWARYogpvrLG+/mRNwKXMJZVnRBxWB2Z7jmPv'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Anqc77khIdzbFpPn2hVjC2t2/K6ys7XLi8vUr049cMpB', 'ENCv1:Ajyt2CsfWN9diVAvbObJemhqyEFPJGSDxYlzZSo++LmmGN3f2Mg6FyXq', 'ENCv1:AmkwTyIgIXNhPpquYzj62R9SjMnkgwnNuYSwQp8+a+ZZD+2CILCw5Khxtq7klecXxOvgruVbCMIfyOta/0jaooPcv+LmbA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AlKEc1gxOphvBHlDGzuYVSv7xrQ3fSIrRHgPknLaPMBcUYCW0AihezqKZD/M1HohUhnB'
  AND c.telefono = 'ENCv1:AkO1J0Vtbe9uXrYmWNovbrUMd+jV8XbAw5F2hyVfa9WCKfmPJM5n'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:As94TR/tKWEWTCaTG0/+Osi5yZKnly7cd69et23F6J0m', 'ENCv1:At1YT+7GjbTm4XS/spw/9+7wknyslk5iZx01A9Cz61JUc5DB7sylzXFB', 'ENCv1:Al6RBY0b5awTo0bqiNcTrGCjeKEP/dhI+OPmpLLBPJUw755I1CF2eY42k88+vqSWl9q2hLruBPZo17xEAaucDsYRN7YfuA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ak51w26VJUXE0NLxXj/DXi0uCo92Pqd/pqVFRJFCrs4Vo/EoQHm5m61Zsg=='
  AND c.telefono = 'ENCv1:AiImOiWKirxin1WMQT4eAy5F4oCPJe081or2iSkOXlWhqpagFsmJ'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Alq63XKf45mcCTxZqP/mSfO3RFIUTFoaSrJ7fZTOZAF9', 'ENCv1:Aosy7attnfYTTHsMf2sOdhATzdVO1EdEgALMKfHdqOBALvcqb+dipbZv', 'ENCv1:AshQ4Gu51trr4eGM808jWQYTyyotZSKK3B7vM4eMqgOhZZkWS2DECZGfQrSPBklh+shzDb7zb9eK4v1XjxQxPhkRDyyTAQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmK/8hyMiwD5H+OJhiIRLOZR0eW+N5U+6hys4S5aeESi2QCPlFxMLZUpZlNSz58F+go='
  AND c.telefono = 'ENCv1:ArdGgp90Bc1+GTdSWO4SialLStFiMEI63fAv+w40sdxNGWQnfho+'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Au2+0vTQstdFadqFBEazFVaxx8cX4eETp9zEpXUxe363', 'ENCv1:AtO0cWmDansqrsEfBVzEzUKMFoQPhROAtFHBUoo3n194nKhpeIxuUFTU', 'ENCv1:AqzTQlpFjf7RQpAXPgWCehH6ZQdn8ysFISrN3w1h9ktVEu5f9M9NaTJWDqFVxmnY7cDhm7ce8lUd3VZvuMnYqyUhKjiokg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Apz0EqHMiSk5ldvTnlZwRzc6w/VKKzSt3VKzIsXiv/2Cp6EDc4jwIF22LAuMznbB9lQJ88OvsER3Ug=='
  AND c.telefono = 'ENCv1:AuoVZoKpNJsScot2FaTcSLIG/3YyVbefZbTw7Ej2Oxxxaa1ZSKJb'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Aowq6UBwKvR3q2lNZiagdZmz0e/04uIknZycvlhKMHPN', 'ENCv1:AtRHWGQXjuz8R21Q7ElbyQLnL20JpYDAg1IxZEFG/KB8UZnq24nX8FZ+', 'ENCv1:AlUBaXla6bSQERt7cU3vCBXQfQvv5taqbp3VlIbs3O/vizMeF30IcaFOVKUov4/aSrP7V4dRJwNn/8NQD7/Xwk9OBePqyQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApYlXarGSRmUAN8kzhAvQWDyiZ+/oeiwIL4ZyGw63b6WrMmfd6WRqheixw=='
  AND c.telefono = 'ENCv1:Auh0WiOWSnJ9rCzvtsqzvxEVmb4cCHS3/rD+8jt1DfOVbemH4kr7'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Amr7cCYXbJ7W/qe+MvnTvfay/XMTG3rNEGUxY1yyxHIx', 'ENCv1:AuB4ZaY5camK16uq+M8jtJmasmWIFGMFQ44htk4pC6FBZE7Xz0/gqgf1', 'ENCv1:AvAIhdl6VhONkNkkgzzyeNChe36fkHij/sZuJHXBVZP3XRx/s1zfllIop6A47ZZ6yRod/eP7zLWFjSBqXyQR5muQz6RQ2A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AlQmhpl/+e09XFLz/D4Z6V+lR3HCyLRTUWJqaNIwH1Hi9VzFRlSmDA=='
  AND c.telefono = 'ENCv1:AiKdX5nyPjP/2SWo3kmtZhb2VwRSURcH+O39/H6zx3KSrRG5pwEg'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AjUWKV5MOkJ5cwQIL7HgX/CF3VMTIbtXdC+LhezGfG+R', 'ENCv1:Aj+tPvrdIB6ZaGJK35WrSywEwsVoeANFSywHkzij226OtdO23RN2N6ho', 'ENCv1:ApUnzgwVCxqlgD/825R5P4fPI85CTOH6vQ0uvQe7GZgB8XedZ1qnyvDy9IjHlH5KDzMeO12AiMvxcXzSg46qUjmpwUouyA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AphmbyWwxtne5ehGtKT4Kq1AlmMHgc9fxQbuScc6/SAwxpJvJfWGG0XhSOy3UcqR'
  AND c.telefono = 'ENCv1:AgAUmG9Q2v3JTwqAWMW3Bw48ryefaWnMjjr1vjZc3jCRLhdtulgt'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ai/azIJ5GQREMdXrKmPQiJtJPWgNY7qUYvnAlywbqPHB', 'ENCv1:AlullFFgrzEUFZ18loMFmcF8AK9vks3A9kso9qrquLemA+T/Ar0wM/vi', 'ENCv1:AgaUFOXH0uoj1wNNXVQOKPT09DHW6SWL3vERgd4J3d8Gt9PPvfFhZS+kgBANUQKBtj62uNz3zbI0w14nvbhbvVTEZpIR0A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AlO9GCzlfZ2w7XbsyDQrtedF9VS5LmblVUe+Ih2bDEzrejB5T+z6xirQoAg='
  AND c.telefono = 'ENCv1:AnscdvXiyMHi/jGtqbT0l2vWitx4xvw1dDKHq7NuaICfVS7HzaLe'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:As2eWJKUkbYw8LarkPS1kouoGuoVK520gwqXWPh2rYZj', 'ENCv1:Ajn2/ktvvNn8+YODGhKdCyN9Gd2javkz0na+wHZp3+oCbE9EWWTB+F3P', 'ENCv1:At4l+ydtNRgj9C6FN6AydMVjILKNa5njMfX/+pTub4jxYEhOQ6+bFXAmxhB23vxLPJSMgRCo/dLMgissV+O0GzV1/ydKQw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AoPJRL3/N2YdlrOW5tN5i/bb/7+/Gyb2SGOdtLnIz46v2seUBOpCO2XJzlM='
  AND c.telefono = 'ENCv1:AptkLjM0JcyqdV+CZLFZ7x/raXUDQox74t6cyGGV9p6p5pi92Thu'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AshN2gLbYR96GHWWQm1ggbxbRbKbYpP7Yz06FjMO74p3', 'ENCv1:AmhAUa3SpmylIj8vzSQs1ECxI72rnCPgcpYl892kQYDV/FqVlEO2FBMX', 'ENCv1:AjRKP4m8aU1DyOiyWkH11HfkDWNKlwZktz4KlomkIUp8ykUYw+5a0JKYYmMEVuRqjEzSN/vehfcF36WcmcXMQqnZdD7sJw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AhdnkrtIeDjLW4LLsp6qUnxCILunhnurOq79Tm62JkDlEnJn6EX0keIc7QZ94NsG'
  AND c.telefono = 'ENCv1:ApRg63sDoZXCLxem1ZOdQuCtU8uB5pwC0yNbMqPsJvb+1bZ+ZAUy'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AtD/R/520nNKqwZSWkOw9j3KUV6ystIV5B6ACBEWWBqR', 'ENCv1:Aifaxa8ksoJrdzxrvCqQTc8fDzn11XATX7WLR62+2jVwQWHJfiy2u/3G', 'ENCv1:Aqp/VSfLFaTldBlJuzMcb9Mqz9mDejPyJW0RV2oin+BpD0J61BLSjg0++Ytca11VkcvlMjush+ssYh/1B+zCyVfStY3Piw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Anrs1mZiFZX+QZoRHdtjRzgiWGA+OUkJBk/S4vm63c708vz/InzvRLntXrblBjl33bkz'
  AND c.telefono = 'ENCv1:Ah7HE6oaIlqQfZjYjWjWVTl5U+XEF6UqFWc1PFZWI0fTyubpVD+Q'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ApIfu3KrmjN3pAJzmaL7YI6MxIosJVatj//jUwalealE', 'ENCv1:At8knFNrO2GtRjdyvPIWs5n2DWfVthhyX+j6jLx1AWuw79wS0MbfeFu4', 'ENCv1:AlbcycU66qWa6p1gL0qAT2qXa1pFxcHNTE81HL6R3s2eHHV8Nmu/oJOn3zBh7/U1xcUd+4Ob4U287GMbUSIUmNr77Fu9RA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AtcNRiQG1ppoRbGyzR35WRLE2vkIRHDTWRhximprx1mTAUq0RmWLsCYI0nwzkgzplG903PJKiyM='
  AND c.telefono = 'ENCv1:AgDneGIXUzoTAeBaniwYkLYJ/ETJK6zIrqqGdVdDmkC2gmdsrweU'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ars7g+KqzJXOaEZ5TX/bSzQ2w2zXbZN5Kby90L542MyB', 'ENCv1:As5uHPjIu27bg1tzYuDDjTtkUc8WomYyQ9L1NEqNXzVt8e3wb6EuJ+Si', 'ENCv1:Ap1fFPuyRWNefgc1L0WYvLSOQ7aLtg8hAgS59tpkM/O6BUPjpkOhZl/m/COMOQ666nzGj2yI8MBfOvuZy1n5RFn+QbJXRg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aii4H2AOTvV/l7koSmIVdl7LFsJNHLsZfJOQ+Ec14f41JJX22A=='
  AND c.telefono = 'ENCv1:AqDL9cZBNuX1qmLThTcEX3CEtDakYO6NmeHtAjSriwtG/IAymUPK'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AozP4+0YovaGYFVTkSwC3BYyVU7v5zs6eqkDWIi3xQJP', 'ENCv1:AmDaeLdAIl48PSlajyfIkRokUII9elbMDPWr9Ls8i61Y0DGxfj1S0oAi', 'ENCv1:ApmV8iZ674XikMuFrnTdbwZ9MBx6Srlb2mZsKhU+Kc1B9EGIa+R6YwpuCjGfY8P4ar6Wyh6emvv3t25bP0ioEqBTg58aNSe2RPxXqACCOg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ap/US7V4/j+AcIN9k21kYUcCfBTSt9nbpP30NupOhHVdvJlZ6GzL0eRFA7010+Sq'
  AND c.telefono = 'ENCv1:AlXorCAKFHNh8m7QyTTBE6/mHf0LBXgZtE24tk7JJ3NKqwo2Qudl'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AreqmEA7PmTqQyunfZbHAsLVMhpVAEfmMZ4j6T9T/NsK', 'ENCv1:Al4XhIyi5uDae8NFFJAvHdUQYFYdSPDGRhT/t3R5uT8vLrz5xTmPP9GR', 'ENCv1:Amdoc1qh06TqpThFgtVK8hcg+etEtLYqmAQpMJw2m6sk7T/8vBl/nyZT5HneaEXDZBArt15P5pbrA/fzKBF2+gXxO3H/oA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Av+jDfglZDBclWNqD72yfyWqPxHTEokxNO7Ba3qjm+PMKTknBxM/yAqUDpeAuZQ='
  AND c.telefono = 'ENCv1:AlhrwPN44DlgbkHQrCwWpsEhezdy6Van1hSuOzn2LBiIITVd16an'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmIFAfMdh8fWHuax+t3ugRUUxiMh63DLnvUt1m5AqueS', 'ENCv1:AnLBCZcEzRKThVwboegpgXB16p/E+wbpy5AqQlJ4Icnlv8bE4KAUL7ET', 'ENCv1:AmfMD6WQbFn5BFJefWbkCVHtXN3LMI62iu+6wixSN+/tJtmT74Zrnpny03DtZ713lmPpFgdcTquE29tt2w62CVGU+vwjwg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ai+OLOegm+f5Bwn7UYBewgwOoOxwSVkWU2kymoRNkSQTpWSXIBfhr0UDvbAHoUX5tfiQ'
  AND c.telefono = 'ENCv1:Are21Z0UJ8TUeHixDrbiP0RcMfz7/dxR4eqAz9mLHcj0n3DMT5jS'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Aot7UzbfsEuFw0JlZ6j5QZJZZFr4HlBP0WpNkD/5EpVh', 'ENCv1:Aj4l8nzqgZpBxUZUbgHjkvYdDF3ipd3X6SrpTO7HnI2byGndzd9CJwLN', 'ENCv1:AkNYnKO6jgHzgTnFBGKHodC9rzVj8V3y6DRXIDuLa95DgvHNgNVs3qECagJ87EzI0p1GjwQRP9czoUqHuQIGbr3YrQxc2A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AjbOSNIHZcJXtoLSZPXCLRCyY4BtuJapIXEa/xuchiUA1UkhY+Efj98FQ0sLwGEe6jgZ3lZY8VrfZ8s='
  AND c.telefono = 'ENCv1:AhtoiFTXNh72XDau4QGGG0yikWSJkvBHOlFtBUSkYCXlF8vMSsUl'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AlodxMHskVvAGqs0SfA1pNN2hsuBNgQ9blyfbw1WC6B5', 'ENCv1:AjW4wcYvco4GoTXUGg7d2+tx6OzGidZLH2pbWBw3pZlcqvvyqArfVGwn', 'ENCv1:AjsKtnmmGL8d2mN5wllv/sLSa9tilZKOZ1eYfA9/25rHB+4q2+VPAEO5c8UQo12xPs1s7J8sLX/TDf7dxYp9YGLEM9P8qA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ahk0HAapXlzlMpMy8MFkd6iecSBtHY++SU01gzfkSSyQ7C/bL3rojO3KEJvJjadr'
  AND c.telefono = 'ENCv1:Avd8YB/+EldLrqGT+8Q1r6KAuNzoHmYffsKj5twajwfb+pd6DGW8'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvMUiwYaxQzZUynKWfRz38vA+9m8BjoW/vm/Ro1OqMQq', 'ENCv1:AmBAZ3g759IvlcGkWqZMY5DstUdGNmy//LvKQeBOmn7roijF/oy0srqI', 'ENCv1:ArV/d+nTmWcdVET8Na3LNBL35KXGVrQa6ELjU1Ec4z7KNPSSD0AHvuufZLjUFuGin5p5CG2IwTjP+ilhsrjxK0osyiF4rw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aj2daD4w6wls/OFv2GTZ3txB7gb5Pi3DmuQU9pVgPkIO/CpAX+5ihA=='
  AND c.telefono = 'ENCv1:Apu7SzaXp3lWSWDZR3LruNzKRH3L9brxVw0fOPWvgt/+e/S2pULA'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ai6eFJuxXgF1z5GwPIHFZ7gBigQhH/jHLUX4m5PRx2tV', 'ENCv1:AsDFpTUUY4Q8y6ZOtnafa2Y9mUVrvSemk6H4uaAxPBRF894+pV8lfTAU', 'ENCv1:AnfyGZgNUehKgm7l6lP0mQrZY+WqkHXdE3M/K8G6yugQaXPRgq1IEi0Dd6vNlwmseD9645p+gctivfbswJ3nDT5uF4bQUg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmOcGYqe8aZ30bSwEjCK+3/h6kbUBZmQ1ve+3HfAvA39fvmnXFCrI4Ti8w=='
  AND c.telefono = 'ENCv1:ArNmY/TqvmaiUEgXR1Uru1TiypxX+hAzAr+cM+BBN5NBOIw9c4nI'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Au5UJ7buR2Dr7pB7evMXYFR+IYGH6uhHTIbijSeTM1b4', 'ENCv1:AqhkVpwdhFKtB7AF0sxUv3iZVCiPw+FGgTAQU64hhz4Rhi6XCWZAcFM1', 'ENCv1:AlE3Nf9pNR8LsTNgIcTj8dwNSPps+T4jqzugjtK77YhnamhrzTGRDNAAucHBCAN+hAcsxUS4MDUwLrgQClRkUMiCZheq7Q==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AtLKjlrz3mavVSyeVXf+2Oy32vqi50jRz5oSQJ0oQCvzcnOItmvZ'
  AND c.telefono = 'ENCv1:AjqCXTiuxfXUaZuyOwWTCybjdidJlSVKEW/cL7PEa7rLSiYPetUt'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ArdSVjAVa+oqWO1Vw8MgbbhLMDkyv5mcFE7ZmuE6yA5q', 'ENCv1:AoTE71CVU9wX9qblmaRNWGPI91+Zd+trlYdCPV1fbDisIaX0NnuEhGEr', 'ENCv1:Aj7xZiLRrDBXB4rlrxUOLa+mRmh8ov12xF88gcfPBN0hs2RiII7YNGxDc2RwFl3AaoT0dHMLhZqYeNiVj+2d9gGDdZ+IsQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AgHDTfkMJQagcCysq1/Wb4R3G1ObARRziCzm0knJS95ZW6v2bebCfLNU9hkxjNxw'
  AND c.telefono = 'ENCv1:AjrMSFoJFfk+Gkbx78kEGR0X47DCoyGJFs5ScvoQ1z5nbiJIZI+I'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmtsD+/rI+ANKWHzUdQCmwQTT0pRJ4C2LdXRrTw7tY/R', 'ENCv1:AohSPAZOCuNHlPESnki2g7nW3iF9ITbdWgQ41Ln9p3y02IWFaWQ5Ly6D', 'ENCv1:AgMCyUXN+bvhj0Q6JHh3/y5O5WX9iCJK3HutyezjQ10HBWPrc571XET3diMqu6lmO72DXlflcLMnsXpqWjNRR5wTZKkfLw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AoSAkXah4BW6j/G3n6y9xG5TF9nW8dCnlPlr0SXgZqTtRLQYNf/NMDDxtwk='
  AND c.telefono = 'ENCv1:Ag2b+5Bj8mS/DqVOCTxRZWwnZXssCHMsVLtrazl12svNOn88EPyc'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AnkWk7z078cY/yKufpP6RPMRL03QB7m30nRXxWpWMvsT', 'ENCv1:AiDTeXYF8LLbyN6XV8AYiHxs5MidZcpa5XjlmpZ4Qr9GHSth34qKoxn4', 'ENCv1:AvWt9S0gnNDUwc1/GRF4ViIZj3VsRYu5xrdkVBY4yx7Enp1PYA6Eh025PCt3QYDNZ8w/SNwSDHFEj4M1CNQZ4DKVCLoa9w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AgBsIupbYhyUoTKcWR1oEhhuNKYoNZaV280I2M1N4KYNRDmjkOic'
  AND c.telefono = 'ENCv1:Avi1Yt9XpY30TqmQfVopHlZ5Jy2ScYS9MIMLR7TPAC+Si7UvYb+c'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AsAEzY/MLBa4Bm9g19iizHLG8a6Ku9yNAiHCNGXoC+NI', 'ENCv1:AqXTEbboUCkZoItwya5dlOoO3nOUhpta5V6mWWaFNmb8qfrVNBt4RVFu', 'ENCv1:AuhAETAKSNY5vgeQH+M+4ZDjvHPc+m6tgEtmpsI621Ar0no9u4T6B4186qESMb2pD7w1y+QEPgsNVw7THQohv9K2VqjRsg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnhTQZq9ILlr2fLDvIcyZN1wXZ2c3iT8MWAZCpMDgzgcmfEnMRk0QzmxtynV1A=='
  AND c.telefono = 'ENCv1:AqwrL4aiJEczR+XgeARKCm7pk/0hYA58h4odYUT2q3P/8TSK+3xM'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvaKcWpN1s1jN2WFGp3lgOIwUKYam4QBTz7yLlNVDV4+', 'ENCv1:AmzVvezjfJEXpAheiZZWKul7RNEksp8YI/IxK+19lLXX26r+4wpnQ5BP', 'ENCv1:AiPg7QqtQ8+APioFeR0BqWD2wtLMkz4n7R/sMCEX00JiWB2aoqoE0+xlWyE4WVta6L26la2Q5Kr271WuCo2AjR2NKiTdMQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AubsUn1peRZLqRVgs8uI4GvC8LAK+0kBwOXvxM3/CaxvDJF1yj+O'
  AND c.telefono = 'ENCv1:ArNXeMSJSm49abwONRTE5uOZjG3UKM84u8B7UDr00FX50IXbxOXu'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AqBcADV4Tn0gko8zWhprSTdp+BJtMJ21SbK2FmOnokZk', 'ENCv1:AhHWGzZETYbrSfVFZ//BTmSgEMGILab77dZbwzsQI7HyB1eDiQwhfj07', 'ENCv1:AtdK8+SfPHGdM56mFhpkf2ikyfAqwBZ7j7Yy/aNARxCXJSOgOGD6XoR9RWKrznZO/xcpC01NFYzsNI/YzJQXkmyTJuvY7g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ard2AWkwOUPhxYf1d7Gz7klNJPUzOrAKXCgZJ0qzX6O/8IT+34OBvMRQSaA='
  AND c.telefono = 'ENCv1:ArXZYyPcfVQUvQ57dkCdmvLR1mtsmip/V3h8EweXnEFVxzk2Ukae'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AveHymVejKuiMABjRmTeeFEqS5rhfQpQb9GThibrCE0V', 'ENCv1:AgbUghYHlNyBazZmuVg3mHwWryVy45YwDwNwOFoWu5PigO9KbF7No4Am', 'ENCv1:Al6YbmqSNwQ60DG8EA0WppQQmYPKFpqb7gN7RnbDGZA3FhDJOxBIlXJR0GE2eKGIs0z9KuXiUj0Y/t2LwJCtBvV1NXd3wQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ap1w5ce/ciSdOXKdL3DH0FxscR75Ih14vMEPFDC67PFpGajA/gBKihKcuO16ig=='
  AND c.telefono = 'ENCv1:Ag2e5ctBsEKmh7elObQf8fsFpEi0ZnGrPdiGISQEKmj7uTE0Zpfv'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AgIKByuTACmDhkaD9vz0ieptXDDlsJpGnEz/0rsVSrNs', 'ENCv1:AvgpcVa+z9QiAlMfe0IAgCkdHbQkLZp4oXliJ/vvR1CTU+kWKHNXaoU8', 'ENCv1:AuCu+U3cPKcUzKCs7Uxb+5lbO1t0vN4Kq6FUUNJcRWAKLT0U2bpUYxjmBAO4Q6UwO7ty/9ii1Uzp84aBIndbq5lSTmSB4Q==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Av41j6gZOTWXAmc8cr4RJOn0x12St2MjO9pHYxBXxRH3e22yLa4qviv1wA=='
  AND c.telefono = 'ENCv1:Aj2xcM3YQoM91WJIOjGKYdUeelYHCE+TwJybXErQ53LBCfXCvewN'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AsT/8s8Ln+dMstRcgbwMQapvbE2dply1+KkLgPws3Bpn', 'ENCv1:Arsa/QSIQi5qANM4EGY7kFIjzDJCVP42woIWtiYC01D7YTy1hBmi4RQl', 'ENCv1:Ap500APA4o+lo65PiniKoBXM13zxgImuV5L+mSpwi7nhxLVPfSdch0gtnmH7H4kyvR0YRDW3VodfYfQhoACi8A8Z+9Y96A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aj5Ovz2jFlCMTvCmPOBKIhEoLsTVmkLzsHmZtt0G9SSAU5U7EbHX+w=='
  AND c.telefono = 'ENCv1:AsEpXu5fgLrAmEcPOg4DAap2GsNDQFBebw2YRhc4dVqiFcV3t4Oh'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AtTsZ9HZWEB4eBYQCcnLljZNh6seX1dBkVlDFV4UBweK', 'ENCv1:AmQmV4v3O1RsQm3+9LF9N73nnSllYQO4b6I3outy/ymwhkN77HId1ojy', 'ENCv1:Anmf1QNPNCT/5MC74PgFVdnWFusI6IxeVQ84xLSv9DDiOGwqVXu5ygD9VuHw3yz/qPFdbTutcSfv2jjBQhaAWISdI4ESFg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnuHF/kgUkBRCnjWxY591ssrbS3sx/QbOFZDnfaO9DqWtNc/VwOExuI0HQhoGoB2'
  AND c.telefono = 'ENCv1:ApKt9sFJbT4GGuSI6K8lvxCF/tpkMexJwLppVhRe9Puj/AYR+e89'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AhhXxM92OAyH8A2gFmNoo7/zuWqDo9xJp4vwNGqEObqM', 'ENCv1:Ag2AOX06U4EYO2rFW8pjDGcx7GMHJMUe2xSalnQGXzdzBqIJES9C4Wfb', 'ENCv1:Ass73DGHniUk3ZiH2fCwDxMfHp5k7D9gljn6PtaecmgRKxUBaQRzrg4nfpmb5CoF02vTEwbc08RyNmqNPsfSbxGCxELebQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApTp0qWRmyddYOxZBBRVvNAB9aaiYItqxqB89zx7lHV4xQpTBAl+vKF4b3OLRaiefPvl2A=='
  AND c.telefono = 'ENCv1:Ap3eU9Kaf+jybvFU/9TZEtcHu2TUeZiwqE/OOIHqf3DZn3WRXKKW'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ArQQyw+Ikl52myFWHinZPKmADdICKHZ2boLbeX9JabSr', 'ENCv1:AjoOC0QhEFiKuURS8cFuYmdtab4NBmf1reNxHLRues+PL54AVfXAfBpS', 'ENCv1:AsYl54suDU8mYtod1S94uX42CtNJpqs+Fw9YBm/VlfnkOkQ/ncgpDOgTENkqn8oKCauXi8lrKobXW70v6RmN08IXuDt+tg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AgHMFZ2O625XWM6E86Pa+nkXVfyRXELSXHlXsfq0yjjDAofmNJe4Bm7sRyg='
  AND c.telefono = 'ENCv1:Anyl1ZKYEO7cTtwoWl59PG7xM0o0v/MNRjHDDseBJXTa60mXt73a'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AgGBsnk0fr9mY5HFvIqmPi0/1q7vArYhaUMdB6I2ni9m', 'ENCv1:AhDLGWxkU8QZgTIA8qqbh60rrSa430cw7ySNg3xzei3abNNaspNBpC6F', 'ENCv1:AmZu/6UyUvYmHLu/XUesMZdEsBYdxNzAvSBIbRU8Gg1zXlFo0T02jiHhc2RpvyfdSJM1ui6+iRHCb9kwZMxwIDKvaABq/w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ahh5CG6mvjvCouAWK9dA2SX5FF7bZFWWMYk2loEmO0nsXj4t7IGUDA=='
  AND c.telefono = 'ENCv1:AgPjUc9c+20zVyoucFIUfROQb6UwLjJ8uDBQxfXH3jrC4X/5hhCH'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AkDhvU98uQm1dFVEvmz5p1mQcdNuEb7xN+PBHbzmO1tJ', 'ENCv1:AkRql+q1su5zx5LYbpJ7hfistoD3dsFCuJZfsnci4vh73+jckYIu2M6j', 'ENCv1:AnCUCJ68lq9qHhPSP21lBlVCYcDiYcyg04xvXT6YxIwOmZutHaP75lqv1fSgCwHdyc/dlHQMFcrWNLpsCWeQIEJQ4JsL6g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ahs/a/X/x2pruUNjGT6Jdi/3jRPDho7RQT2A1r2LjTeRreoJAgS6esWM54hWn8w6'
  AND c.telefono = 'ENCv1:AlTP+U9AwK1vSRDKFaTtxFNfJBJT4u75acjCEphzjynzxccX41LH'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AomeiiFu/q4sHZjUfo7dphhAaKCqdWdqPSDUMKdhoCx8', 'ENCv1:AnjJy3rDC4mpdbLMO4x8b9x0rf0dhRgtmL/yXp15IrXth8yp3Zdzurr5', 'ENCv1:AhX0jBOlD1TZEchXkPawPUG+jK2aawKoy/2zl4aQtrfsieLUbRGbe1RL1cH2YKKRgVX7pxCuMjE0EEdETLRWEPNMtl7+YQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AuaXLm7ymgbiyIRtdDsnlFkozfqbSnMhnZltIdONp5SSAlStuGcfRszMFSwB'
  AND c.telefono = 'ENCv1:AlE3InRrr/ki4x+hhlicNnCczsiCz3X6oGlxPmT70grAS8QS+zNQ'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AoJWiVq1JBfW6vsruiEPcnJZEPIg5djVj36IJWcaWCdT', 'ENCv1:AtyUzD3kw0Kornp09YWCqigtYBu0DS+HlkeeeDN9dBVNcqRXhiK6m4+2', 'ENCv1:AhyHjEg/y5e/wJEYAObuUn8NMK+1OhsDaVJjNT1GyVn5EiCfX22qTB8BAmrTY99qG6x70Jf3X9FGivbfq11LU1hqW5qtMQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnGHnxDwrd11xR8gbhnElklAXeQyYjHD6RND7JGC5o1sNh3d2yuigW8Kb5UQ'
  AND c.telefono = 'ENCv1:AkGF1+evP/x2LGcqRWTzF5w4Rgqtz0udzyQBiQuWeFIz6xYW7gkB'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Am7yy8NBqqUCOeReJlqSSPzleYOn0UEPXSB7Uf6jIHfq', 'ENCv1:AjEv2wUH9rJFhD3gT6wstRYZZgFIP49uQd8n25T3VIORxCU3CgLUyiGB', 'ENCv1:Ao+yfsSt8sgugptWRA63gzfQ0Mq9Db0fBFyYTKXKquTac3b/KJ0wS7oUwRWnPwj/yEMxcItM4g5A3vBrcx+Aba9CDph34g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:As5eDu9D3eE+OyqqNCpuXTXUsoVFvROsUfTP1Ggsjd0WRpGCS6j4'
  AND c.telefono = 'ENCv1:AnUj+gVUTxYtmgFqJ/tUaOFhovqhNiof8O/mfHDMB9JhOI6s+UCK'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Asx7ELFXNwMbIAcPyGuXqJU16ywYBMF3tKrWEf2IwuJU', 'ENCv1:AoYM4sWhysimjorJeyLbJJB//zVTRdjRGyl4QdsLzpfFcMOLBKXa5rHz', 'ENCv1:Akcf8gL3X6yUrJqydbiCoeiAPS+5klHgHmoPtoC1ptD/5QaHiuX9C5yOC7iRHSda4/Xd7/fEGAH6l12neAloVJGDooYUtQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ap3+pbT0I9QX8xVs084xM+ufu8qqiU0nDmjnI6kIMXE6oHNP2UFt3kPuMK5iqLJKsnvW'
  AND c.telefono = 'ENCv1:Ai8mqPmodtkSQOvqnPvig5tQyYUcxlx9x1Gbezn0i53sNGENLD77'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AueBr1GdgxRVt45ovFSizaHUFCfyBEXkq6eg3LraBAJ0', 'ENCv1:AluBAmNTQSnZTVnP51N8IKMBfBREkMDtqmh6WGD2ujiPsI+noNdZ/xRO', 'ENCv1:Akmf0o/CLGRZkQF6m0c2zIvLvp27AkHg7BrzruzESJRy8jaCoQw8It2ASb8L6BooNqwp7kRZPwffxCPLAox8vX19Uc28OQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Al16LeQhG2DLBa6oYR0y6tEWmaGwUcla1UgG8prO5U3kClJGqB8twBcdSdFUB6Y+Yir6OX/4'
  AND c.telefono = 'ENCv1:AiCvyYd2RlWCZtoiSE0qZt9UusZMmYpCfiymmEarkWj8RGiE8lrB'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Am2cgYZz9fDJnJE1ZnjC0C2SSWb6IEHfoxLgYW9Id/9z', 'ENCv1:AlimiisLqSf3p+F3Bss9HlDRQk3c3Y+gR1Ov3vFDLkLC6W3qRBZvApVC', 'ENCv1:Au3WuCUwJzf4g8/XsyuqmII38X7QQEJVljk4Fa3/2zdqNkieSoFlVjnykJUpZZxkQju02+OKnBzfpsvFE7Cx+4reLAtsHbz8868pURTUOQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AoHh65BWQ5cZhcMoiZ42jEe/QZaS2IwmhUqQAhj3SKZ+2FVMQ4Nlv8CQKFGecw/4'
  AND c.telefono = 'ENCv1:Ap5swU3sgMdG7tf0EaX5hlHD1Ic00B+7ugdlCrcqswLxarT6gTR9'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AjPSec4SHsexfDeNwt1jr0L/KPOiN3pvF3KhrF/7qxqm', 'ENCv1:Anbs6fCh6y0Zgn/Hvacxu8WxHG6q+uyIxY44l7IK+zGtFn4RfQYRi5nk', 'ENCv1:ApAbWOaw2bZD0+zb4RVNgEutqVIY/RAV2p6CzRIUrOHFjLfb3q0wjAe1K3lMZKPu4VtwkUGTTfBRKIGD0AwLUiamQ+n7aREob6fHPVNuaA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ArbXVHP8kYYsjdAZiUrp0ZZdnQBaDsw7nMqFWTFk/J4f70fmULmurQ=='
  AND c.telefono = 'ENCv1:AmomQ3fOincPGipw7bzX9XSpGMNj6n2ZqHhPFtZkNt6Fbv4St3av'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvZ5DSBtZmfWw4uLM676cmslyGWneqZBVRKXb0tSbwnA', 'ENCv1:ApsJHMIbCXcMRk6+fIrAnnywusJUhHqhaR2xjXM86T2OwrsxP0TlbBJd', 'ENCv1:AuEXVgFBAoU5Ws6rwIrN89+7Z6NkOuVUwwDKjx30MdWmLKWrUwRo97Gu8k4tsjiiVGdI5bstXeySi7OSFZXzRusOteU+bg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmePT2HDCzUliDEcPWBD5O63U8mk5dVp6AZDcHAMking3a3Qep4/+XJZxeuT5BJumQ=='
  AND c.telefono = 'ENCv1:AnEtCZX1qEAI6jE1TL2hnbvCGsPTBvwSFVStZ+GrRpvrFFQ0xLyk'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AiTWCX76GR5RuO7gkK+nveiCqwlZzhgCCEa4rM8kgFrE', 'ENCv1:As+Bv+q2gNdmZASRE6lLHB6ODcGckYC2uKLtruKrPQuNrz4ESDiGLZtX', 'ENCv1:Ak7JhmSyknkYImfrPeis5GMyA7sjSZNaSfVAYeWz0+ZtL7B9W41q1FsOKH2uc61RYikQ8xir4iYaFjseWFfUsWRbezyuXQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AhFim/cqthW0U3B5704pyVLRDBXmMGeN8Co5GTWsq7xFw+pdWv57KW4='
  AND c.telefono = 'ENCv1:An93rLc6zo71MfQu9TwcJYLUBjom77gezWIYeHH3cQO63WEWDGmJ'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ahzl27TeHrB3x1kVaqCO9UtKymT83EQko+BAjxi9yVEp', 'ENCv1:AlaHY9B9RwqEmtlfnB4nrFszgwk3aiB0JeTDyLCwfyCIcIKWZsTnEW4o', 'ENCv1:As/DgnGfvgyaqVTpuqxQAZcAZeF38s79WYESrIQpUiHGQpzJBzGbl7k5j8f8H0tOXEdYwcJzrDLy/SbB46Y3e/A7u3vWAw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ah5i4VTeGnbNKhzxcy62XHU8s6aqAPyuSLlC+NV13NJuRr4jIEVCe2oHHKUb'
  AND c.telefono = 'ENCv1:AqhpzOrLGRcrhoCgyBjVzAXNoYmw1AMx/cwpQzZIVAV/n6/unDrm'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Arg7MPc4fOjhalCzfDQ4j52PrFjBpq22I+b4xhbtlNBs', 'ENCv1:ArNNJup1Na6rpQtRrabAo4y2PB3zwTXBfIACMY8VeJt/fx4E9+xOIK/r', 'ENCv1:AqJHeU+GdFlqKfVEEjigCjy65pLimGfYVL9pD8vkePbJo0TczO9qrSsZYuN4tsOk7MP1khkA5V2eyLvV/B70mv+95od37Q==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApFlaMsBycfiDArlllnmDOhnzPXZoB7ZFy3ItvMVXVw6D78BsLs/RDs='
  AND c.telefono = 'ENCv1:AuxHRDHirpcNjHKMl/NsPmWagggk2/BiW1F78CF2DR/o3mQvs7Vp'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AsSkBK2WqNvVXbn7gT6FK2xXzDhoPEtIIKWyVb/cCLjo', 'ENCv1:Arsq6DZcS5fi6G2xU+KkTiELj7AImfHWM10oO3Gt4Fc5XfLoQNPoGWp9', 'ENCv1:AjsXPcZj15IoD5pbqRB+RiwkxkNbnXGG82CB3zusu9QhX0knjcs2QyeclXAapCNU+BMLvQM9D2ZH6kDc0lM8obW+k8mnoA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AupOsicp4VWfNVvT3HY0k7SX/qK60pSKNLZLOVXJ2JxEO3h3KDjkIJMJ+k+B998='
  AND c.telefono = 'ENCv1:Au+UbHn9lw51BNPU+OFhSVgxQJ93jT1r4DGAOhxLWWkq8ZG1gMZ0'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Aqm/8JlZRLAwAFW5056WoezgPMa4HNBnPTCyqFWxZN8B', 'ENCv1:ArZD8NbvwuJ1aKoSxWzjGxKP0ih7/LPNf0Rtp+DX+Kfq5ATrq16M/NE6', 'ENCv1:AphS1I8UL14I78yu3SaSzLjL48O1zbZ6kKchuWVQuaS23QQCVjdcIVSOloDK5dPZFf88CquEx+epik/ZrJgaFIa6N+8kIQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqJIEBtSUXr59GA0469yrI2AjFK7WE2zaGAoZmQVgcsQ2pion7nz9kXAE3Sv+byC745r'
  AND c.telefono = 'ENCv1:Ah2ExldZFoQKXgJq2602QQRoFuFdgruZiACH62d8chIQrdb6wEye'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:At8dWVE/0ywSi+oNUm2R/N1uaDmlr02IOX1VgnPQcbhj', 'ENCv1:AsPv21OejbMnKoxBLsE4anugZu4vyyDur3Bs91dNio8VPAhknJ18MgKn', 'ENCv1:AtIwi5LhlG0AGWXidczvFdXMyfMLs/rgCJrR/Pk/iveP0aZFOWPSfp07ApSTn56LS8A7HfJqA01KGlKSBb7TCmY6r1P9+A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AtirgnzVBvzuj3lK1WNA87yFwbYDvw28WfM8es3d9izPAfnTyndq8FRdszJsYVmkxc8v'
  AND c.telefono = 'ENCv1:AlJIQZzf36CAoek6t0s5FcS9YCuXcvjSeO+7HsNhs6IRSkUOMIN2'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AlgKT++t8PRtNQ5HQO6NxMMFlzeG9VBAAo6Uk2fk+hZ/', 'ENCv1:ArSKDy4YTy/4en2bj3YFz0iWFYFNIpDGmfTnv+LhbctPtFfZDs5t/eFN', 'ENCv1:Aowkc+pgc1ThId+lN+VZt+jXdBmdmgCQpKACqNA8XQ3p+CTP+ZUfy8AInXERrjjV7hrNf6a3/WsNhMjnSbWQ56Ukp40mUQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Auwg1ToDX4rnl1aZp7pj0jVcjqiQczEjKEoOYmH1dqqZor6MtJl978w='
  AND c.telefono = 'ENCv1:Asp0gXRAyKjpKV4hszstejYi9Ynp50qTdw2E6zsuvFxstEKQkKsH'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvkTNS2hUnGyDUq99gvRaUoCM0b8XcORJRCvDdPczfuK', 'ENCv1:ApqGUgnEWz3+fJ1CskXEYiEmfDRv4AqTI4qlAYQaBlLd+RNtJSlG0bZW', 'ENCv1:Ap8EEFPoQrPIRv2xV6y/7AaJ4D2KjqYedb09u2FZLKgFoDV1LjdlAi9e0ZCjtKL6lKU6z4sD5kNZR43daTQ2NHnrwPSrqw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AtvBc2Ab9Viwv/YA/5vp0BpshZyMdc0vDekVXqhFNlRszDvIpe6cUI7LwQeuPu3dmMw='
  AND c.telefono = 'ENCv1:At3Qqm02X9leDmgLGauxCq5vg58I+rUk/G0v60iWjJ/NijH+5Dhx'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ah2tqqQdhvBZeQ44PnvmQudl9QpktUpdrEs6ZzrqQlYU', 'ENCv1:AgkmS1CUwiIqnZuw48RMkilJ5GZE0SoTEQQSPwK5FU7NdUKC4OJIrxco', 'ENCv1:At4yXa45RF0ti1vwlnuCQimOFTJk9n4QY1M+byedXA0mOIlnOxbEsrv2ZP00GSCFeAx8mfOX1jpX812JjinpHBdJ8fBvnQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Au/mYLbLqB+YNhJuBGzZ159AJOf6zPpm2B9TrT5sHYtTYt7KhJgCFinEwpa2VPNd1g=='
  AND c.telefono = 'ENCv1:ArylJ9Hl6N38PLpGn4jYdbxbJZeuyy02N0o/W8j112P2fSK6tWMv'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AoonDR/WbUgZ07Mu5vbIU1UMCOiFqU4XmyDT6kY//pyU', 'ENCv1:AlE8PUyg6MnAsrr32t73lCQh78V+cxla1pOaBBvK0NTqma31Og7Ht7k/', 'ENCv1:Aqe818U5BR6hYi1YzSP30vuC7EFdvgJ4mjHx6pCC335fAoIUJjrqOj2t80jRQXNXjCf/bY64qHuZi3cFAQGEu1otTZNF5g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AsLrLnkzAITAZN50i87QnwKaDemr6VICf3MA8sF4E+t+WYEQEsJcskg='
  AND c.telefono = 'ENCv1:Ape4m5fZiysfeBHFKjN3TbtY20LOtQsfO/wopk1igRELCjzgoohy'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ap4aN2RdeADqGCSAiLR0uSdj3N68XEIanLwwGlB6Q3Rw', 'ENCv1:AiD9ZHhQJn3E8ai3pPwAvQwg/BiWNhetDCYAycYLT2rqHUY0a3eoPQPw', 'ENCv1:AqCiED7WNFiGaS9cQfvoL5SyU4IpuBYcucvcBTzE1BQSmdt3x/zm9KdDK4uIqbKyPk3a28duWQttjsgTbkCFXlhDojrXBw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ajhs29gE82Itd+gVM5Sy6Lkws9sLjtdXV/R5RL4SvXAdggPvdFh0ks2sqQE='
  AND c.telefono = 'ENCv1:AuTbpip5b2qtvaaJFhKaDPwMBpwDBzoZ68IGaSHpVikgmLHj6Jh5'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AtaHWu91dXV+acyadqPDKMq9YAqMb2ieKYofonaR+rp0', 'ENCv1:Ap1+JXZ4cukQ81VnsId74unKYTTP1dhmpN5Jd0blA41/tm2UoSY6Wep6', 'ENCv1:ApQjs+NTrNp78WEer7D7x6EOAXE6ic1MMozb8q18RSKCDhUUY8pmkEIReT8Y7Fv8KtpI0A36LgZN1l5lH136wSmnH8EEuQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvQ8gk4dBUBqmpVthx2V8RgyUbN6oN2MVYidkgNGcovFOrsuNBHm'
  AND c.telefono = 'ENCv1:Ao9RJV40gE8Hyk1yRX5ft2ZmcqP8Rc2INuROiQI5eJm9PUaqXFKj'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AlmoPhX5mHkVI2OAajHXXwrNutBYyIYMJc1sLzkK/LJc', 'ENCv1:Avpg63o7uK8yaVrrVa8qbs95qch4r243H/lWLKHUN46grIj0wbKNb/AK', 'ENCv1:ArQOvY9U9q7sB+U7r4WwYtO8uQ6pvaSqUZBfLII/bOeYGD708BJy4hSwgXr8CpZzOrwhfwIYubi9qte0gG8xtLo4+Jg00A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApdLEyGi/X9dv0rnYQEWvqFQkzxqJYcuqUAViZMe71oUl/TYm6EEzY9s'
  AND c.telefono = 'ENCv1:AnBCo3VGD5mRqoTKYPAq9I7HCN/ZYTDOSC6GYyoZpvlf8LqXljx8'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Au27Rbp9FMyVWRGW8aGrpWFnC5oInCG10PRLTzKfouda', 'ENCv1:AjnZM0623/M2DiRqQZ39bh5lVSwbJwFn6nh1+2uyEJe+NueR/qqyQOsD', 'ENCv1:AnDv1p8Ic8iVK3y3pd0FdTGPXjfhHva0ABi1OTW6zQZHh4bWNS3UFJ4mmVjdifUT2XbeLXUJ2KBT4d38ggWVr3QcHXWgvA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:An0DI/93N2nruoPnsOPyKa3v9fUkYq/jm58ItRYuLupQUSORGNWufuMoCa3cgbdA+HD/HNSo5w=='
  AND c.telefono = 'ENCv1:AgM4u8Yukrje4dSXKHksrmSf3DbYZVWxhEE2WHMox52VAiZnmAeE'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AiuljM9VwjfzuMtsjsjQtuORHlMzXWC/UCBdZvU++1Nh', 'ENCv1:AnQ6ijqRWXhjGBdqkt3lWoAAjGiNDsZSMdt+mYIIY/Qdha44446Po+/6', 'ENCv1:AlAERn1gP0DcKbpb/0V7bR7i0eOSF/ipZTIP/btXx4BsPlhxgpxZrUbTTtVCpvkn4a0BzBa5v3b0qx92nfJM1lhYn6emNA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Am19nNTtnDBwFJ21bRA9xXLJvE+yYVQDRMK1IdYqw3TyIWD4CjelI+JM5JPp/QE='
  AND c.telefono = 'ENCv1:Agqj9msx+LsKQ4JMrfCuQ4eVpTEGER9yodPzc2Nh+t/RLAOGvJ8S'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AqTKQ5CmzxR7QmnTGLdcfBYeHlbHNYgbqjICWwLLwkLi', 'ENCv1:AvUSpDEnqdjb4+WNUGhbJiNpRtfb2ZYQe0oUkw2//1lkBCKebyvzX3lA', 'ENCv1:AkqMSdwDUpp5SrlIfZLxAJxh1MzIWpu9BxtDbAuKobFtIBmBRuS02SofvARNENJiQN99IW/RqM8m097C7v3s4OavrMVQbg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Av4hnhN4gMPUQf25XYHralzkh/4elMXo0pznMWVzdgc4DBIUBovj8I3DqgJCF1x+CH07lYdPQECt+w=='
  AND c.telefono = 'ENCv1:Are/1Vvle4yIfGlkCXBq8eGz6TVNGRm//3iUkMaJb1PDqlbkdK3+'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AsH1sojBiN1T7+4u6WDD2XFIfLNpis3eRdVxwytTODYL', 'ENCv1:Apgu/I600OsBuYSWCAenbsCjYnCEWecAByPEt0pHs/UU0RB7pX1mUa7V', 'ENCv1:AoxujCbH4xSuWAc4eNIv5Z9qcGeMmzpto0EbMN0IUGgsD9As0WfJmOX6cCsbQPm27rJa8zkyCVIDM6yqUCpX+Qpg7oDdjA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AhKqXy04K8QJ82zQT6tIFCK9PIyc9ArEx3FDUd4U9ucH6DwQ0vjCW5R94A=='
  AND c.telefono = 'ENCv1:AhE3pRQBZkjSq0LuyrVQ1suh2K/vQV+9+begw59niwA3k6hFOsoz'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AjvzqRtVoHlz4r3Sy4fcESXA2XB0C1cTjl/kiVVLDL1H', 'ENCv1:AnyxyGFWink8GMuKdzkW5fAxWNohClAJD2VpFk0jadFg50XXjaa4LlJN', 'ENCv1:AjW7mSSjPqW3dAo3vJKR4yQgO5x9Maup93FBMvSAkfyj34ODVK6uxPSxdryHOPwP9HDksFVI6yaE9ETDKwo8tnDdl3r5IThdGKFGNXAc', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqF10eRj0iWexOAeEYwcIfjrqAvP2SZB4kaASo70EDOnrm6SMwkbuxal'
  AND c.telefono = 'ENCv1:Ap+Hj6pRn8E2Yf5l+JELvFTr5zt3Qfplzk4QY0Mr3eKoZIFhcUR2'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Avto13xHxBwI1RjGP+ELkkdAHx0JymCKhShEW6X4DPTZ', 'ENCv1:Ar7vS03DZVBoc/AXYHC8xMLcIlUW3zZLwdWzmuDBfgb+ig1U3a6CgAsK', 'ENCv1:AvZbnrN629ELcNXuOLfufjfEMVa/UOhuW0VDlH4khOKNcOflDrBwezsKBoq6xYmNvw3RMx6zzwvZj33HQKU5JXR+mZRoJA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnQM6DdtLkyayPGtI0thT/2kjDXIbSmrrMQMIkLGZZ3XXUXXkHR4+RPHTIo='
  AND c.telefono = 'ENCv1:AhFLvAcfpxqhD9WwyQTgh9rylaycapvqKeWM0H9qbQixIIZMXlfd'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AtUGTdLvBfu0nooy/u7IQhzG5Lg+IKK3HEOQRrltWYeS', 'ENCv1:Aom1dnlpDyQByXC2qo873VJhvl/EVIsSBdmBUUH0ugeZxXMsew3oP5Tn', 'ENCv1:ArNWEWUE6ynEZwy1tY4gt6fSqHIoD0S2PKquXAsnKnpT+fE77xlCovlLx2ju1wZkIkC0Wz0z7YC1zrnwqUNdGcFJgKaYCA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AjrrVGPVX3gwHOL6Z8aL3Y9L7QsUf9a40MQF9xiJ6/qwrVvQgEKoGK4='
  AND c.telefono = 'ENCv1:AoPpUbZMLkAx1CS0P3lExmPhuf2EGHPl49GnoTuf/cW1pD2dNBxg'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Avu/4cw3OrBSPs9HcQDXtsmOVHWWEJukYlgM0HFURpp6', 'ENCv1:Aun5y1SxFtUvLzx0pseRwrpeXerWyWgkR2i950qhdmAQVXaCGrWBFyrB', 'ENCv1:ApYt4B/wgqEp25lH7tpUe3N4ZOqEYnDDoXo65OLO5609VyTmDUaAmUHh5Czx2iLXrQkQ8grsitckC3SDm5TItwFxQFI2Pw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AtUX45/Sz+vVtOd51eNkZH/vqFBAPio+oL882vtyr6iAk2Aaa12ciUU='
  AND c.telefono = 'ENCv1:AhkfbhzZxhXLi5H88omkVWdAPqwKDSqRmhhjj3pkqsaBWDkA96VE'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AsMOdzfrBmqz5KEtxZtChJ2EGkE+r6M/lDzLwkvZA1w4', 'ENCv1:AnItrfqoYQTiI/NoAFf8zUllBidpeKnHKC1vYQIq3eBW45vv6o7vZjML', 'ENCv1:ApEY0aYmmT2pvA5aOdKnjge4lZqiCnS87KG62OTbxzqaOH8FEYFiePzJdgKNrBoGxEW6Xy0n0H+zsSix2M+syW5Ewix0Ow==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvSvf5C4exZz/9P8WTRm9sJgl9vJdFWZbR4YgRrkpqhAQfHEOx57bsvAW00Sl0HlnskwOg=='
  AND c.telefono = 'ENCv1:Avwlo7jPDCQbjIFKDeEm088gK3DwYAqwinpM6AxlfJX7WJsOmK6S'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Am/4hU1VUm4pXbIQkLx6toKc51gUx71uc+N6Pcu0jUyE', 'ENCv1:AiDf4hKHoO/yzP9dlV4+SmqjIzQCHqi6SMkAaEWyXUpTm5aptlwhahIW', 'ENCv1:AtEO0RI7mcIrqHD3zRBBfSaUNTowyUu2zXVaURp9g8ki2Ezfk4aB0hT8r/Dt5BX4vKFJ6zHxkbP77ak0N7behVhnAmkutw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AoxaXndExujh1Vs0dgQ9uIlXay7EMkcsoXwmLcsxP3ff8q7+FGsmYHKlcRl3UXd4UbCE+2+A'
  AND c.telefono = 'ENCv1:AonKHnmKvGA4RHMNTGKB3CYqHh0br+/U5YWa0+TIrQbE7FwcoGO7'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Anb4WxvWN53MVv0dcc9CjPiKukeqaC3W/OWPK/qjbPQv', 'ENCv1:AqTNnoJGgMDmsjEY1Ww0YqDcaq0yGjzWPlX3LD8cah/7ACT3E7cwirK2', 'ENCv1:AtCVkGlPEL+9AS3Jy1KaYgTpUmaPEh9BB2SErkk6XdIoHihAlkHAjZAN24y1RwaxoEMMDTW71Mqali7y9dKpzPGiyBhfGw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aqk8brRYD63m0nn9CQdaPXFWdlAaMWL42mjTN/wCIOxezQixAgkR1DWtn8UJajjJbA5BVql7'
  AND c.telefono = 'ENCv1:AofGdUuXP3WOnv244nGYTLJtTNXC1FyXFGPUy3CjW+KZ2zVY3//k'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AkZ4NgLHW/r/17enytH7jcMDJHPekY//rx4H9Sa+M+CS', 'ENCv1:AkOXCffyNvFniGIvG2/XFdFVT3OCapkxH+CCYrIAt7gWtfS3vWwvp2u8', 'ENCv1:Ah6ynoaJdFZxK/3ypRcAKqMQxdgBnhGnWuh49N7EyoczRW5zLdf5v4+mGP2+gKPit9BVBIo2GFKHOA+l1nGrdhPHCDGbig==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Agc2C/tsAu/GcWWXvuk+BppPRAKFGrkNvJMUHNCd+kzbwCRqkOw7gnKJkpuutA=='
  AND c.telefono = 'ENCv1:ApHmhLKBvU/5azrzulnyY2h4Ppb0zuW7XwrbOFC/B74PF/a8Qft9'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AraJQuje7/yTKr4jlomP8xlCENwY0ZOSXjcOXgyQRJoH', 'ENCv1:Akj89QGTzH87CjvVUI/1awuxt4lG/e3+FtGBY5jYHuD0fTVE7hK1nZ9g', 'ENCv1:AimTw2NTQOsefj0CbBgjJyfTuF4pblVzV4ArRZia+Ah/V0CFECAeeChMy+N67tx+PAEyQ+mDP68yDWZQN8KbJNg/GB34tQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqE5VwVXN10lwzD8zMtQKgtjJaco6CbJhOI+IL1dAJdv8wsmGDBFIjN/'
  AND c.telefono = 'ENCv1:AifuppxbhZ63EU+PR0T+OkerDWFz7N5ke81EGnplSnw8cP9xGIhZ'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Apu+6opqC9d/tmfVN83ahQp4qCuNv+gDFpcIM4cuZ1Fc', 'ENCv1:Akm9pWaFMggMWfFhuNY/FeLxYZ44DknU/T80et+wPKi5FCZVZZAkhQBP', 'ENCv1:AhIuv7uf+HGQsjLq7f94yrZLWqeq7XLyQ9R4yk4Hzxz0SybLsh2w+c25MGzEqgzDJ/RASMoZHE52OYC0Z4NQc67Uo+pebg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqefslkszWlH0t55ruG3DrnnI6NoZL6S3SIL58DOuv85RitHjGTL'
  AND c.telefono = 'ENCv1:AleA59iBjibJrHlw1YvrMPlu/4ML5NLBJoP4XZFDyyt5IPdVBVS+'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AgEo/vrjk9/+V1Txxl4fK4V+tmtwtnYErW1T+SGE7lX+', 'ENCv1:AhwwcI7q4UO0v6QniC9qWYQP3kTOZIbB8i2k743muS8j2A467BimZmxt', 'ENCv1:AkX5DNxCCmiq+seIjD6FfAwbB0K9jHg30J2JsPjVo/+FH5X2zqCbjhqRHOoYQVhzWkuoBkOG+RlM1sWGmvo7LYvBFA8o7w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqqbT/Yy30bVuB6vwLIe49WheBWaGpOtkYwxu93DCzqtlZgudJj5yLffjakQYzpp7Q=='
  AND c.telefono = 'ENCv1:AuEN1ovB3hEQrvYfQJqgSilS9eUZaCHoHVY739HMJFtATC74eSUK'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ar0jxyjxtFFSG1yLzXFk+HvQzXvzLtrO15xSxc5AsPM9', 'ENCv1:As263SbTvIelpgj+HmUoouA8X4V3lOIY3+sPr+FEOuPO6tmiavGy8K35', 'ENCv1:Ap7KhXzUpCi2KU6c0yqO8aQiGUXT5INWngb7sW53mYfttl91gYTn7IvJ5slOfFW3tt7+brQvVd3dzvqsCBK4JEIoYEHb8Q==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqYVodEIZUhs8TgPhEpm8FZ4bnuyVEAypj8YIts8acDQ2DJJS8w29LlFhwM='
  AND c.telefono = 'ENCv1:AufeJ2V5HDyim4Xpg77D1Ah/QQLxNJa/fqrNN1Tf2V53yKhAvcAv'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:At/cwVq4OJoj9MI04vhyUhBwmcyd6OxLuWBeViugl2mu', 'ENCv1:AsMTmu//+kEgl6sdPV7bW/VzmMNVhHFerp91gGR4zSy04Ius9hZljelb', 'ENCv1:AiFwqmeN+L48Piv2i+RSz4L9FzxXv/dDSA0A9SZOdK8ToF5PNEBynj50QfA/S1Oa7uOFWMAXdLYLyxV/+LfH4DhgWaqnAw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AlQD4I0svd33WxZti9EQFByt44Cob7F5+Ob1cluZX2Y47PQx5uJ/vzPgxXNtR4jhtA=='
  AND c.telefono = 'ENCv1:AsSaK6JhodHrIUuVOpmc6g4R2/TxcZT2c88WybE84xopsQ92BuM3'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AugQmDpv42/tMJQdMPjXOHJ+0OOwXloXVnG+O56nJmzc', 'ENCv1:AhGujS203QO+x2bZNV7O43Qi6sRw+2TcEhziq148ZPtzJnWczvRIC1ZR', 'ENCv1:Av31Ur8Rde4qiWUnMq6x8Cij3ORekeF78wA8lQ4G2Sj1tBMeinX/wMefPtVtQdolM4cIK2unc/60dOmuvVRDo9Rh4GLqTQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvBVJHIfKR/yBPCfjAg5rYSlib7yJTXL8/R6rJYxfvIpKp6ngccxtJk='
  AND c.telefono = 'ENCv1:AsJpUo+kSzes23jELvlrY/VnDFRMku/WdgV5sO3Kt2flW5CWtcJx'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvPExtAHu0IadprFK0G9IYmJEIdV5P2rULn5u6xe6Xpu', 'ENCv1:AgBWynS35aJUx+BdsEGvBArUQ+jh9dlvE0rXxqfiLLJRJr+Z8pNNG4wI', 'ENCv1:AhsLa18e85PME7weEifiZEMtEeAhtcorjvQxJApoJ2vvsHgOEgueGkD61sG7M3Fti5ota2ruVHUGQ56TSOeWmYVUWyQnju174ov0ZXDq', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AuvgGhdayAwCHiYc3bK5FrFs6PLfvxQPh9MWqkRmNZUHYAjtRBYgt3s='
  AND c.telefono = 'ENCv1:AgMTm8aGgSumml5O2HuvbTphgpFi4dln2mP5iTPpn03P4b7Z0pKl'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AtPhNnCWCij8R5/wsUuETgDQXH5c9voCFXox7ApwXSy/', 'ENCv1:Ah13plWigxBbqpq6fqZnZleSBOsIyXJ1EITO6rdNnPdkYsX1PjcP5WQl', 'ENCv1:AuzYLZa07mG2b61rPHz7r/zo6unKt+i0ceIF2PJ4MhDlXBw4qk2mZza2r2TNZkQafncnUgARiUwO9XoB4diRs/9HRflyCA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnAJ9Za0y3G4fHp6z/eVIymDJZH5FOKFJ9kXE2TPANqVYkwIpwCVOi5pig=='
  AND c.telefono = 'ENCv1:AgWMpMXBRiP7uAxU7lFS4EC+68AcQT49jlhC4cUOxjtCylZSHxDn'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AoDsp0JSdD/+/9lEnx2ZLQshLAT4jA/EVa5RyOE4vSHY', 'ENCv1:Ak7tJ8G7tt6GVsTp+0FNA0s759xNoTGuO0ulbKsExogUySvzzppUNaQk', 'ENCv1:ApXbyX9aKFR1aJsD1cRXxjExSx40W8M32H1er2g3ITG036lVLfj5DDOT6MBH4aQaknYQkkJiyZU2wmN7dIGrXaEAK12pig==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AlZt0ef6aHUYBYtf7Rdbq5QWQkBYj6WZ5EL+60trmm7tmudp8IJcZzw='
  AND c.telefono = 'ENCv1:Aubzy85l6U6QJ5/NO7Y0NpmrBDS0ogdTv42XKoR9MbX3WPYIBwHf'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AnDcjkJMFr/zcBo0ZpdTHtk+Pq1IR1AujAP6w8IR9oKd', 'ENCv1:AoKOFIUmHmrdECyu2p9qLMsFst5a5eDJLqhp5LCebBiCCeujd1iWE3py', 'ENCv1:AtCERDznE/bXDk1iMHf/uD9VpYu+fTeg7MjtrXKt7dY9b9hjCE7wCxSjZoIMQSIODhZFp9LvLvueIcB2H4R36tUe87hNHhgjPR1T43r/', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aud4AysHMpgEKPX+6u9Fi7IDm1o1Tp0JRPYKtCJiAfsZAS/pMdfe'
  AND c.telefono = 'ENCv1:AiPmyLFIYPnyfYgm7c4iONvfa8qmgYOH5hR5XA4oCQ3CI7xrUwr+'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AnJZCgyDO16mzkaAZB1Sge/RidZ8XIZgRYJo3QdQ8YXo', 'ENCv1:Anr6M/Skg0rzvrcnP0SXuyqtoRVwImeg6gLy1JHmGZiv+PSRMd00qjPc', 'ENCv1:AqLXqMQAgcmlEhbZOuOsPCyE0HV1WHyhwV8SAss+FeGK1HRA3JCSrl7Jx8RpPeLtYFzWP+Hvhdu4hvplhamJGC+9RtxMEw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ArM9EK+BFJ5eNSbdQXyIKeURcnBy+iMNDJQVuTJyBetWUhApPnUQ/Lk='
  AND c.telefono = 'ENCv1:AocLyZOpxHLKd5SedDOLMSumqj2Fd2SqrSuwBGHtGd/eHE6uGTWT'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ArsBNq36wsfYl6CYhihoEZxjinPzJotwhTyPhfvcCXd+', 'ENCv1:AqQSvVkR3cBLclamWB9VUqgrFo4rxOXRNvc++7ncLGzUPTGAHoE7+oi7', 'ENCv1:AkS/Q6RyuqfMCQ1FmaUJ0n0vwKH5o0qH0nKh4tlcQhRsOmjH5a3Fw8y/G+SZ0olEok43mm6RG15ZAssIKhcyHsAfacDiLIS6IsoFSPXF', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AuhI9LJfDrumYTfHXxXXImiYTO1LQoTjDjq4qE3HbCTRg3W8YSTuVzHZDmq4pcUyPpS8sW6y5IUEIUCc'
  AND c.telefono = 'ENCv1:ArRmQHkuV8SAPBnIxgihGCnz6btItg9HlT0AfXdEuSgrmfPZte5q'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Apz/UVhMN1tG3mracu3f1SoQM0Nhhg5UB5UFFAGJ092h', 'ENCv1:AhwmCgdSF7P321pjhy4jQdi9axe/muU+tUi2SHC9ekiqcaLcmIanbkqD', 'ENCv1:Arm9+HQjgSrioVK8rAMqn2UIS6Mq2dOUsPh3S3sOiJHd382QfL3Di9GfzEp6UOfAwT9dl3OFvtaQVfQ6ZTCUthlAjrxi1g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvHquY0QVwLaXdt2S5vwDeCi9W/ivjPfEmT/x4BWfMc9z6/uNHsbXQ=='
  AND c.telefono = 'ENCv1:AnhFD94H1BnXM3sGQ+H1k8ABgOYIi+cEGzhZH+npL4EYmtVtofjp'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AquAid3+1AffC4Jc9WmLTBzKSKQlmyZm0x7zX/UxtnfR', 'ENCv1:AtBNZKdKglniGZmwI9OSG+gRWDHSBBqvjq520+2KFgaMrZIJ9RHrPMUA', 'ENCv1:AsaV4vpagbauT0vGR42bNvG0mZccowRBYU7dPcl7W0gh6E8CbdRkhiFmnlMt1KcM111whUcoWX6Lju8W8UNc8wDUr+rr7Q==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ah7/mpDslseKTcIgGYSq6WvViMrzDCW8DuUvbdhTspu4GuJqZMJMSDEHcg=='
  AND c.telefono = 'ENCv1:ApVTXXUoAvi+dIJ8UZGzydCYjrfJWrCHKrkBcgM0+zTGKBRyg17Z'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Arhaiq6cgvf2t73E663OVdn+4zAOclPDoCCIx+JjCk0N', 'ENCv1:AmQ7rFiitXRTfYTRDvuW8Az5erXaLDj7XJixwio9/zrcL1bbTRTEbzh9', 'ENCv1:AltAhv9GEs7XdB/ee8nKmVnEjKHvzRey+562hZlJtjZ4DFyJnQkVR4NNVq3hhebxY+xanROxpeOxpWdMXrRqcTZBGoTM0w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Al5HK0Oln3bddQ3Fu08PF2iAkK9Q0jPwnijwytelZnhQVZ7YD8YjbQ=='
  AND c.telefono = 'ENCv1:ArZaZHmnQaaGiHAMi1KAypG3V15lZ1tfpOeHDpIuqzhAG/OT68lA'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmTNp1TXRDk6mv4Jqyz3u6qeSiNPH0BIqhUdz9axXsLE', 'ENCv1:Ag3++/ffLsc3dPzWknqtgQalANjI7BLVPpIoX3fHhbGfj7ziKjFgx/4j', 'ENCv1:AtU+Em/VD5TxOPv6KSyZJrnkfwbUZA36E3Ml/YDmRMzWbZM5834mCvJ0fa+W25412x9GEQo+FwujM80LUXho7S3eo9JTkA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnIQzJPjtZrHkiVGdkFHEEoKpTEyZxuavA0lab8LVvpsT2ppXwXpOCwB'
  AND c.telefono = 'ENCv1:AjYHDTvFILbB/vFt1CX6m34ExUyr8GycC+gebZwnrvckaJQsq/oM'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ArcavETAMcYjmvukPUuJINOosdZtMyND1AS75o6oAfJI', 'ENCv1:AtLzXknc/3xWq+i/i6cG2Udm2L423BTBOBzNQBNADNi1M/uuzewpk8jC', 'ENCv1:AgFtmGubmmnBeKjcPKC/HqMQ1QJAG4XqxXWPJQMN5m5RY7LL7KzYVFI65IiMJGCGwuzq5vLbVm7cJCU3aAufuinE7AAHhw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AtveQ/9+SFkIk/NxZU6uFB0HxJ5K/qLcUsdmo018SV8z29e1Q0ooDOGbIqG6ekj6YLd2QA4AL+TsP1O+wxZHAs1fpNDiNQ0fyKige18p'
  AND c.telefono = 'ENCv1:AiECZLfCClxKD+lYXe4edAOqrvoN7ECAyHPBPaKjdYxkBrRS933B'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Aqg1lNrLEDgGi0GxuAuhwy1Oq8u1nSode1k68RfJTGJV', 'ENCv1:AtwzNwbr8KDfpCwuEhBkSP539+00W6/4htXul0JcJfKih+TNqkhBJae6', 'ENCv1:AqeDRPVjo1+hlXU00yxeVqcQJsPPiMGnINsakfWNHGwEuMMPaOLFq/Vg+Tz1kYfsjPby0YKmbLlgbqKfqy9kRmZTRtilPQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ajs+QmJu6daSWTNxS1SleCI5OxcmU9wQFMzIV/+mThi26L6kp+gl+LXZyhNS2gdU'
  AND c.telefono = 'ENCv1:AmJEBXqXDbm90SkxTwvQ2eiW4YDDXqnT6lVciLEr1y26I1fereI/'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmKluLOjBw6chCkAnJf//lIyYAds17s365Gu6vV89jiS', 'ENCv1:AigbpQ6aEiG/WszlYh7wLoRlsHITZqhrqi+pehBrgivvu82KEqJAgbBt', 'ENCv1:Ai2DxtMtpuh+kz5ZYd3BsfcaJXFYgq0LDmPy1RCQJK2cUaYmPzjTLVj/O+pDr4vybWfRa8Nv+EjIU9kYSt4JAHrF3KQgxQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AiwKVgsXAojfGPH12M5EiNg1vtv4i2+RtFZTuz9rA8BdyynGjK7EMvc='
  AND c.telefono = 'ENCv1:AlMIFtd1tFufm0JZaOls1fEQan1XAaUrXk1JNsxf5Db/y3du1+jp'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AgEzO5Uw38r3+zzQxq+rnkrYrv/wf7sl0Pdt3A4OCLbi', 'ENCv1:AoG4tD26JpO0gTYtfU0Kj9SBezW8kLkT90hoCVPgyvHoDl1XLps/zTmB', 'ENCv1:AlQYLlKjUmtssPjx9X5mQpeXy+w+DnnYNc6NYGSxBkw5+llc57NgNeC5IwwGeikSVkzdvEoQXOLY7RJmPpy1DG5IyI7XWQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvGwWfTJyK2kUKmxCWyBn95Uzd88DMiHqHKh1LvxLQGysF033pn4'
  AND c.telefono = 'ENCv1:At3fq1dqdcXzyCgsjom/70U1d2MjUQHOz+QUv8QRcK/PoVPsHV9/'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:At+LJMemQmbhyu0KWcImeWdT0BIHqPouT6da4tM+4ZdU', 'ENCv1:AijGUeLZNEraBLLRdyWitnB0iB2ug1FxJwiZowqhxYH9xrha20p83k03', 'ENCv1:An+u+y7rZG3JqwTIlRDexdZ8ACoqQA29BE8cZ0clOlVrEo8E9/XHIoO+FAuag2Y1wBFUUpZ0zqnii4zcvmmjyUKmvg4FWw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AjuMyxehlm4gu5U+MZAMzsRnL1mhWkalsfczBwoYy5MBpHsnbemgFJTgLfXyra7H'
  AND c.telefono = 'ENCv1:Avl+hRu4W0KJ0x2zLI7kRi+uHw3ND1MWzSEOY1jaSp6wDm45PCh7'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ApXhLV6KxGYDe76pGFkE6sg/mLp+O7XdyRsreM/W+epA', 'ENCv1:Ah4A65SAS6nnxNgRUuq62MmlWLg9KE2Fl1ndd/E88VL/VYsaPA/xafiC', 'ENCv1:AoPYjZxUBaPnP0ofqPOIr9UJAEklMCvQ4gRTdjRVvWaRUfqc6tmEo2Xt0RgZMN/abUGDbpZiW9iGFTOjgddBWSIgK43Tqw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:An5PsHyvcbxLdkQW0u0IPQIdDTvOTj6Cja2xhxMwuypT5VX2lzf2BOVMvAIWYliGPA0K'
  AND c.telefono = 'ENCv1:Amrqs11Aa8oCc6tdZokXIvB2w2AqZhEN+p+UgJk6s00VhJLFdX5G'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Amph4y8GpuKVfmymFaIZ6D6pB64WpFmsZOFkm9UBwGav', 'ENCv1:ApH1Z/a12nDXspiC7lwXSQ/lymG9xPP+djLvj8G2jok33nOPvNT8Ol8K', 'ENCv1:As1eDeHdTA/UNOwa9bygc7sKsU8U55KZrsQd/m/ooBsgtwbh/9r+hYflnHE+4EIu5x2TSFffxG9GF2ZvGlf9uNftv0mOZQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Al/2l5PaXZfiwcUYK+6par8/MiWEvMVzItpQfOeSD6jqRNFeSizb'
  AND c.telefono = 'ENCv1:AuxnNvq++E94tAQAw5fXEStmlpr0iwj927ZCZax0qevgWix8oDh8'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ai29E4CVJyzerZZrr2zWXfzZGl3jOwDT79X8tznTm/NE', 'ENCv1:ApOd2U+JUEmAmfjykejiIbsvMgbhDe1Cet9hR0t79yd6VahQ/RGGgLXz', 'ENCv1:AhmlSGBe26airQOgPC2kxEiJmp8kiho08cfb3+HKLiX5XySnmyiQbilbbXfZ6CJL0EkkHz+7a/Eo8D8yxIhPvIetWDrTUQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AuMFMuk/5fWfRw5dNIXrMuPkArTC6NiQ47yLnesdas3WuLvGgyA='
  AND c.telefono = 'ENCv1:AtSkggwQSbR7dgBvhZ5hbJzM+A9dDqNAQiySzyps49paH5XtNXw7'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AqigBNCYpxRixyujy0lvZe6bePIYWkkCM9LxbLA6l8H5', 'ENCv1:AksamnEOps/Iiwvtr9TG8yyDxjwQWyOlcAIZegHLeflkLudm33kXU66P', 'ENCv1:Aio9AfnZrqrvqKfDpRwjTEEgLE9nrYfYTgUn4jp32LgMG7+lncDp5iZ6ePuv3QIrJaQKTv//R/RDMosUgGcd93plQM5yzw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnZxY+ukjai+6Ksw0pIt2rYWoppWpLmBe3T9k3ST3G0xixCQaapb//whuhn+'
  AND c.telefono = 'ENCv1:Arl1CofuaCnFVHYwKrlyjFyDfc4spfcRG9wPSoS31joeS9KObiWZ'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AkwEZa3oZbaF24rhTQtqGb5P5injBA19g+iuiUGZh3Uw', 'ENCv1:Ajd72+BWwfUNfXwR/uTL46dazvFMupvYX99UbjDvmyBawH3Z4gch6j0B', 'ENCv1:Avq5sysuLbzUGeXfw0eGgV97FA1wklu2CG9ipUYT8YhHWXL/Xcn6d4+Drxtr18XPfG//dYvsjv8f4uJG+exbIBs2xWjM3A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmKg/SnYZ4S3jFiq1naPDwd6oSTTydQIfnblplX0kfPBDboxNf8Mhdsz'
  AND c.telefono = 'ENCv1:Alu85v0s6JkTyo1bkYxfk0MaZQIIJD9Lz1N6wLfRjIXwblUR7kLR'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AoxqtdiZYS22338/FQ9z9JvOErBYprjkTFYDTXe2Q990', 'ENCv1:AqPEX10ukW7JLJwyZM7Om8tDUgzazk6VpesvlnrFFUPil6cTebYwuqbU', 'ENCv1:AgRurNGHrS5slcQjkSiAhd+q3FcGxX5Sgmf7o4wwIfwv+GIDu2cMAk4wmQ2G8gVbD3VfT2gE/jbeL/+h7WFSNxYjTZgAJQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AiRl0S4UqZezsArfkZIP91jUfEK7c7cGnlpFvNcdIRdtD6QQCh1emfcU'
  AND c.telefono = 'ENCv1:AmZDG7WuK/Kd6nLi/mqLFUGaIJPCVDFrlB30cLdF4wznYnvSDHG8'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Anlo76PiN0XTb09d5EXw29sYR7pLg22MSmyONog0PoeN', 'ENCv1:At5/yLmLhHN9NzuSfYnXWea7vGepr4BeV0MaSaKA3mnqSLKobXPQqGze', 'ENCv1:AvnSAdE/5OV5ewedBHxUeTAIQr/yFtDaNFoAjhwLBu6zsE4DlwO0HogzEr5wAVhLiakTP/S6JHTovIIejcqMzFjXWLnF1g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ap9boNWt7O3SwVgBxNMbqOc1ndX92rgLw1NFe4mQaaE/C4UHoXxo'
  AND c.telefono = 'ENCv1:AkhZ2I4aVb0lcESasH3HswEqp4AhZMx3e/smrFQgleDfVYnaJ/r9'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AgFoxXb58YU1EC7xpk+K2zAXab88hinoWRB1IfgYjDce', 'ENCv1:Agi6PPMslNo+gnRNvvjaxF258sSNKj6iAgNDEvkcltj7uO1oGuLuRNTL', 'ENCv1:AvyTALeKu+KwVVlTGtkAS58dTGPKBbNuy6XzJ47LCcxNQHYvyJBM7eqy+CyxtPyPmjoADw7qFG4eo0xJM1XL/VeoYIRCvg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ArSFm7p9Rg3luIyVTr3eoDINuvXzdZbXK0ms4JqD8h09uUQhOObL5Xw='
  AND c.telefono = 'ENCv1:An0ekRI0cPZ4iKi2qPg+dTb28gtdbiA0UKCquKMAmXfteEdv7Yq7'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AuZP7/6yqRzhuV9zZrR0BmJKgj3N8jlPlOQbYF2pMO7+', 'ENCv1:AoZXsRZ7nT9hsxhc3GPG92CiXrxtBMVaaMqjkxAzqrfQFMmaT8ih5xvJ', 'ENCv1:AhwDF8C/HOOiFNmVPNxGVJLs5ozamWwZ3W1C8DePIfpdUL9aVImFi2QYPpdwZeShTPiDrTREDTIZ/ku5K8z+boM53isj1w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AgdA6PIcO85iuEPm6JEjqazlKLBr3v77riHg/OVzRdVBiP84nqQH'
  AND c.telefono = 'ENCv1:ApMh+t4hJzctnmkZSdPYggrBgwoA73d84JW/vcd6oc0LpRweWNDO'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:An6sau/dF9M6chojMLiZfREVvDjwAXp2Yty3smIBjrve', 'ENCv1:AuR2NudyVcdG1Hkey405BKVdLjKZo97NRWQQQzVWGt/4FojpJoVm/2Or', 'ENCv1:At6ArFl3mOkz0O+nr/ITpEgLl0rjSue5GGyzg3caFuj0OtFnM0bqPwwEpBqw7o+dJHszZJpczDSEdRsScQEbGzixCmJiJQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AgseF3pNz4gBkOlk993gn/mKXzIkzZ477Tw/BTCWFDvOZdGsfvZSrSGT1o0V'
  AND c.telefono = 'ENCv1:AszI7DZ+vbvHMP8SwuwEMFzy6lS8hQony9wgiTOqTlUzzbRAdHUT'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvEsjNgLwmmazX9xVsY/22DxNqNGyUowIUPwGh3H7HB4', 'ENCv1:AgbnfZYZ+56z2tZFzsVmO3MrHAuuOvHsPvHVhN1kKw1rtTzflvKjRlwH', 'ENCv1:AvOCq3HJkFHJg6ed9URqqdIU5OzMIuFSwLGgFCChP0IzygFtZYkqiQLrI7oMezhp5eD0WJKEkn7oQSEd13ATCD92AYJSCQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqSaG3mUC0/oez41V5ySUZYRYscXxrBYAZs4bwSZjhW2aPuhe92H'
  AND c.telefono = 'ENCv1:AtkLDC0rgTc4Sp/np2kIw+0O5TjBsX1HNoHN0roBx/+oa3o7KxgH'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AgckozpdBSXrDEnGdxe5VqbSwBeAzRwkvGpWU7F1qs8D', 'ENCv1:ArQwArfhez4lXxhg9vyMJvqgVf7EhNsrZpoWhHWaqfPnV5rY/n/xledQ', 'ENCv1:ApCMmL1r8fHJ3HREn/fUzr28RuOKEZ9jYD6x+UXfcq9E3WaY4KTwgQC6s90KDJ9H8OXIgkMgkELRAOW5ixlvmBiaFKtR0g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AuSHOnnNZ6RpDhTtkhSWaATGo1VSvGGCznyEXXT1hQL5saTpAsS5Fw=='
  AND c.telefono = 'ENCv1:AuRCv46ZNoIW4J9pGMxkzt9WKqO+HKvVd0v6Mz1gZX8gCo6MFWa1'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AljeIfsuL8abymr49QlYfF0IwoOPCDGSkYRwy1oK2sht', 'ENCv1:AvvqEiSxklhAfGPCbyVHCVjGrVSXSOPEGJN0nT4lDFD7pz6y7tN6T29B', 'ENCv1:AvssTwPk1JGZtzLqSOxxMfoANdzCa25VYp7uEcOlQrSUoZ4oWDv+Sx6fndBD9GgEDYRQsEBbIuvO8VIo2pAoCQcrL1O7OA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmHCzeH86tP0nXcbWCB2ikK/NI8RwxZN8JMMTnBhsEX5+oRgVQrM/Ob2cQPFAnA6ng=='
  AND c.telefono = 'ENCv1:AuR5TRtwQWTpETZpOVZCn4qPf4jmz0u8hqL9N46b/nO1xEQBc0n+'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ahimg4JZOMlrzJsnrn1VBMNCU6sRovl0uPuZy2bC2p7Q', 'ENCv1:AhiTnOyyo/r4fb1hZT6tjEtdxBXxArRqCqtjuWknkEJHHkzqGkN8XLvG', 'ENCv1:AnTxqsAZvuLeWtpybJbNjn1+Y/eG5jg119dhB73YkeLts91wjI9rs2oT+q2YwXT23wIt7um4gErqlB4P2klTNTPdnRvC5w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ArnYI4DjmF8OHAjlnWYRu/K6Gbaxy9c0q6rbeBsJM6lwjgl0W7Hk'
  AND c.telefono = 'ENCv1:AqcS13IC+1I5LRhHbwBT5yHSw6GDH9vwW14OdnkD9MgyIQO8BZL8'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmZXtMxJNjb9WUhdVLI2eGDJ9URrTTmlKXGOtkzyNLzt', 'ENCv1:AjW1MiXZGxq4hN63OF4kHZ4E1OQvFm1KAsWgZ6uiHM4dqeLTH8fn9veJ', 'ENCv1:AnFOJlGp/bQbylhDxNj7R0S+Kz9Fy9zF9gdXE7vCejkdF+uvHNXKMjcKXvL3SZ4qs231NzNd4rAI3Wedzy8vYINydx2Ltg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AiLD8+wFsi7ihdEyVZ2EJJDjkHqxnoc1ZTBNwHi9nSVkYYs8LXvV'
  AND c.telefono = 'ENCv1:AvCc/TBIw1Nmj5RJd2mMgDPG2Ds4UMgnUPN0vavC3wIrJjEvOHA7'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvDz2ilV09IwiE/yymviH+YYR5eiffLr4PGA/4rXqzz/', 'ENCv1:AkUrLlg+e1zR135oYhr37oCyqIKpYE1s/M0u9RbBU6bNXDm8Bf1+nIfK', 'ENCv1:As5bTBwrwuVGTljiFldT/fFNMWL2I2RehBe2WYDAJzIJHQjwsPnKG+OFSpd3r0xmymgwpwyoxvnXYYTq+7Rejuctgnk1aA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnYyUNAfLTPQIWFOY79ZWJZNhw97nNCGMVIcY44PXXrR5/idmBtt'
  AND c.telefono = 'ENCv1:AsKbIpWbiesWWnHLVQNgj22AT4FXOUOa0FSf2rGHRER2w/m/iObt'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ag8IbnCrvUE7TiadWXUoeTcd7SR4wqpQGne1bKp/h1lW', 'ENCv1:As333V91g1vTpBYZx0Dqv8Uji49z0jn7x8MYDOu+2AD09oNSekv0rfUP', 'ENCv1:AgU7GbhpsHp1RZvY2LMi/A0e6bQW3gzCsVd0s4DvdZYjjxwfAmBKw29Cp2QU1P4RCMhf3lPNXNnCqKf1KaZrs6vo4Mb8bg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AtbETqAzXlbu1H/R9O+d+b9f2GTs65DLqsMPYYsSIUmzt9HycWc='
  AND c.telefono = 'ENCv1:AhUxAVpwdhOE6I0YI0MwbY/+EId0p2C95WRI4FQqoMUjB4metJRs'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ai/TTFVWCe1NvWj8lFzYSgqI8V+ral/TzQccqN0QvUQ+', 'ENCv1:AuILkSBHo3Guzn2R2lstaLb1nYkbpflaKJOI2xHuwgGWMrvm7IW0IMrA', 'ENCv1:Am6KpyGV4yfzWT4dGn5dU9idjLreYiYUVTe/n1c+73ItSrYT2NiJfuK0eyCUbLBf7WxacpQcedSKgT2T5OE0uhDp8NRcaw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Auw1CCUIE+aGBVATIKxWARZGuloQjtgEoI8G25U6uBsQV2osef0A6w=='
  AND c.telefono = 'ENCv1:AnM1kFtbw3fw0Rb02sCmLPv1nJ1S6h55iVL535CwAEE+zXoGhM7L'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AuvknWD6R3HbiaKG8r961HWJGO7QiIn09AJtby1As1Vo', 'ENCv1:AsPwnqGHTGHnQOkCnzzoABpwnpOha041DC855lqzZ4mZ40ZIMIueLhyW', 'ENCv1:Aq5VPnnt/FPMFF6gsHVHWmGJNi4OCWqFuHI1Jx+VDc4wiY6o9OEkOm0v60de+yGJQkKMwheCz715W1OxKafAKQFgefO2kA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ap7OSXv37BaIFCDWUetSqen4SUCKpYW8QIDRAvTWT6Q+1otSpNhfLfY='
  AND c.telefono = 'ENCv1:Avg6Mw+inPeZOmJnL/rVgX/x0Vn7/+zttT2N5chfJqtYYXwtK7Tw'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AnoHnuEuMvJbFvQo0DsZlj0bZm++48XJVrT60lu7e/ZL', 'ENCv1:AmqVzBXVXrRGLMKV4NBvDOPoO4eqm8h3obedYvEkh0s7mjzAv0IK9EFh', 'ENCv1:AlYUwn1+EESVemY/mD7H7pUc8/6ap7xQfX5B7xsQ60Rri87y0FvFqxj3SJ+DA1kYfK0BQ8esVOknBkfq5BzcShIwZLccxg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AhxXDsuJ+UpiCB+x+tJdATdpdQzlwNjgwqd6EuX0cCGqJwEyffKXKOFSq3BNrG8uigUMfRudLHLixSA2+cz5wA=='
  AND c.telefono = 'ENCv1:AtcfzIrAtdVb4i0K2v7oY4kfXOkhHUDnJPHNwkYOoGhuYbsVr2fO'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AuHtUzWI57uQZQzt+ebC2nBZlVtcU7kLc9ZmrmtFfNGA', 'ENCv1:AjZqfT3i30Gsi/zzj00PtviLOuxC2L2HEUf9wWRr6Wn5N7NzJRLxiVZ3', 'ENCv1:An1aJmmC+8Vy+YBClJ6VmYj3TJf0xpE/kvYT4c69jQlH1cW3k9w9qHRFeDrKXLFcClxPVvh381lSNEhkMEPhWE08fc577w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmM4/PONuLMVs/Lb4YtwGBE8E47jcC7KQ+fPmPWnnJzaAi5yPEsj7RuTCEoIOQeRwKMt2xAXf5vba0Jn'
  AND c.telefono = 'ENCv1:AjEncnNlRmEtSwGU17++IxfGmOVMlPkyqNxDAsW8CGh7G+LsLImv'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AtEuwK/sI1MSTY/JRgu/heIOmMBxt6mbcUCxMv0kVAMm', 'ENCv1:AnqWIQv9hBv9PSHC7T7qlt6Yh2cvUE58XJiliKW4iFv3DhUEPLoRPaMH', 'ENCv1:Amal7xiBsmgQ+fxy7XXY28GxNHswmiE9MzNzqQ4kzoPXcW2SDMCUK9lJdZc1XMnEp2KR8Kud/Frn3AhMqOB9r6YFAaD7lw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApBJAojz2aBibBTf1l2nQ8hLhZlBV9ZU5eLSPjhb7Oeex2akIQN5tAwRzq/CRA5pdw=='
  AND c.telefono = 'ENCv1:Ai1cju5IqAAmAVH3a3wuaopC9Qya962uW1ahrRBbUwiftl9Upbge'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AlX+SP1nQ/A82iwcSPkIQMN09G+uAx8EInC62H+Jckwr', 'ENCv1:AiClQrOtdS0hJbZzA7Hs0Rj8GJ0iiP46216ls6MYlw40IwVRCv2Sj8d1', 'ENCv1:Anp9kTVE9dB2Bc42pQxAaH21TNB73e+8LFZ2BN0KhkXkzHSb/vwYnAnZVLfY/Y2uNhi33q24rjkN2gMX5ehSgf/cq9wQwA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:An1u7xnt5bJikn5uFjmRm8pp+qVYlq9lhV0btqGHbF+xeMddEA3PkeWtsg=='
  AND c.telefono = 'ENCv1:AvfHutSvr3bKaqRjGmuDiaQDyPu19NcWZGfDJCV1GWs8XF13ZEkG'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AoIdRFdLfU3s4WmxWgRqwLb+U+1HCEX9pddFtEU5gstc', 'ENCv1:Ar35QQFoEI1OI5NqzMhHB9Zaa33TDOrkVmts2E9/sdPCFxg4VrT2ZWma', 'ENCv1:AiulgTQJuyUj+PYG1Y0ueneh29o2Facczz2rQe5mrnDfFh/1Sm0xm48DliSZoMyAfVg85SgKV+zYdDcdCt9ZqfMjUFo4ig==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AkzEJ4PITl1cjgBZ2du5mNQ9shLvahjcG4FECsqfGw8EN7YfMt0='
  AND c.telefono = 'ENCv1:AlLQ+Od/MknP7V9Ke5y3USRAmi03YgQicAQlHp3aC8vqMMERbcJs'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AkysP0x0REY/LzsPsizDNwzWK7hSJtHlzUS+kIHdX2F1', 'ENCv1:AnTsNv22IK2iB/B0Kt3A1CXKNQswHDyv7xAufKn8NLII35+9G/RWt8CI', 'ENCv1:AjD44hFjUNwL5Z5PKaU36e1odg3wG25z4xY+QcAeYXxiMRTBZeUnCt2mznaQ+NbLWqKRHBdHz2pNuoXhqNSf+fX53aTsdg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ava1tPx52Nk2//33kGvR1C63afkrgogUvbAblgmrfw3FN+uEq7dpuAOdIindKq0DRk0='
  AND c.telefono = 'ENCv1:Aomq/pxW0/kdPFkP/tkys32CmvuGjEhPdv9XAVsYkZ5HQ4xgfD3Y'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AtPX2RmXaEzZfA8UefajOM/KuCJIB2Urh402v0CTCbHt', 'ENCv1:ApURnQJyzqTyf/WxSxQHJVQcjXCyQK61zn321a0GCnfJDUmsZstOg3/j', 'ENCv1:AhvIwHVO4oy+kwx2F6G0r3Ut1qKZaZkx6+SIGaV2/ld0QrKVRxkfxMDF6sXJRC3qGD61g/P6wYUU6a1Vq9DYWY1j38s8EA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AlErjZtNWUZJXD2Ya/CgVQpIPzDRDAtirU+waZB/UYkta5ypCzec6C7x'
  AND c.telefono = 'ENCv1:AnJXKvWDPEhWCrhl/priqzTx4M1KUe+5Zj65uPFtXMw5GdwCnXRo'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Aj5qT3Zp9cw2DAOpvarKpTxHecB5SJewpgdf3LdKUNDs', 'ENCv1:ApQUKFvCP9qjAgREOYUR8ctM5DrSpxi9uWy/6/ZawdFTNY5XNRNvrOLv', 'ENCv1:AlnQJEhWOG13qx2XAIPz0OGH/1orLBhFeZEwr+EyhzcC/rnnEWRKJ2Bm/feAgrRcMZT6aBRI0cNLW8xzD6oi3ikhyJ72gA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AqwExdgnrC0mZtJSVGlWKSQTvnNZWmn5/8YcS+fQV7il4/B078D0hg=='
  AND c.telefono = 'ENCv1:AkDuaOdt6nLXTgFO8+olQc4hX7eu44vDqoqoxFll3FW+WXbXl/FA'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvbVmGFtKzoHgCrBI30cnONfgj0xlwpFX5iAf9Dvuwre', 'ENCv1:AiD2Bac1XbjpPkBg/peWZMDrMMZlnal1Czb7TPx7G9qYsf5RmH95YYDU', 'ENCv1:AlVDPc4f35cBfKbBGwkaJulnZzpNU+Y3BErOkpaDtb3p2BLSi+Jl+pjGCTVYmcJpmL/dGxjH0MVN60zuqXGcr7Geart0kA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AkW6Qi50zcAPP7yO2sYEtr3qv5Ipvyfi0pEsEU+ABZejgvv+FSJ7QKDIGZ5H52A='
  AND c.telefono = 'ENCv1:AmTGZ2bEnIKcKMCclbLBdyPhW8V8lqvJ9CXy448udvJzmYGGX+IU'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:An6f+SjiyajEa+NNdmtiujemQfX+m5LW45S17Vmqc4DL', 'ENCv1:AidMyPFI9M5+xjH50Nh2kthqkJb5cR/JRR45v/MvTOd//7HfTJiLbASW', 'ENCv1:ApC8Q4wc1Au6oqvqmn2l5MFzetkL0Z4U4S/++EfkTfre4BHVzErlMeSZaIAlohuMsAupSbQRpvPPuWY4MvXpRXElY4rVuQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApKclv+EDZwIvLebM7dsoXtP+wuevHVq3i4voOy83Jw1hnw0Z/23CBVAcgJho2k='
  AND c.telefono = 'ENCv1:AlOlsbqUH5Dr2/PY48i/uhdH1gKhS8ofiUBPcexkvrQgYC0AsXt7'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ap/k2RUFczkpWx3BklAZeAG7xUbH89fRAfWQHz9pXSk5', 'ENCv1:AqFceB35g3lN8DBLPIGUdcPI9PtEjFw0+fqJiLZBSoI7MhqZoNdZLH8F', 'ENCv1:Al7EMQIcDTeJJG/FAVip83wUwO8fctsGe0+rVydnqwFH4kk0+OKYw8CTzmeZDMRiSd58zI72LpbDToqJaL6gcse2IMWrHg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:ApJKM+0n28ZBn63muVphXWdDcKGgfxU5HmkJYYsoEncJf2rBlg=='
  AND c.telefono = 'ENCv1:AqPzIimJT5CWJdal8KBbOey3Dj/hWBfGJPx5qkYT5pnxHBilDFfV'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmKcdwnHUAdS+Km5cDt5JvtTDng8tZ2UiCYd4PgoeqL3', 'ENCv1:AvmORGvhLvcfZdFhIw3r/kw828osA0PBxfJiXJhOWDrwpwMT5y9B2558', 'ENCv1:AtQr5OVkncy/6906u4JDsew098hLvvu9H9ak17Wk3ukKL/4+5oJqDgRumPtUFFnm4tmeBSvK1mm0NV7Lop8+TpwLHNb0cQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ah8jxh9vtegwsx/jZmXOmIvD9psO7YWht4RJm+sT5czWSYPf8hz42A=='
  AND c.telefono = 'ENCv1:ArO4UO7kDpba6A9znzG6i0RzpPL2An10JpsgKkA0K5XEh8ZIR0JG'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvCSj3VUit2S1Q++SmNY1lkvGDMp6WygiImpz2K45iHg', 'ENCv1:AiZTdB2RfniljNT2FFRXFYgFdNWF7Qoj1Kgx2N/LQaNexv60vJTmR8kr', 'ENCv1:ArL1idSOCYrtcdfrmSuB7FMsF7hPsbxAyyNrj+502LDP20W/TVZ55tiw/arDu9zpSKjNqAhgZsXGUYEWW9nkKlX8/esgIA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AizrtjOLbPYJU7EmDm7R4x9uybDwjZpb1AxWlb++Xw9WcaI5R5hZXrrVW7BGFYOxjgLttQ=='
  AND c.telefono = 'ENCv1:Ai6DJO22VDeQuv+Bk3uMDgY1hBIXxAOYfkigEaYVL3lPKDQCLvPG'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Al3XwtP1iRHzhHhgzRBlHUIKYcPN1Q3zE8wymCbZy8C3', 'ENCv1:AksrtNYaAOVJ8MNK3e2aT7IIsua4JNyCGMw0LMUbTew7HbQsfB/umJ6k', 'ENCv1:AgAWRpT14PZdDjsmIbVi1tQ7x33TOVuYcQvINiJuK6qPnBorOVgv+Yu4+4/jhkY5F6LuvztEMAD1FGfraZY5aRuYWzykig==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Agnohy3xptyPi4G0ifipx9ExlasZpf5+L/fskgAyz09tv+EAViqy'
  AND c.telefono = 'ENCv1:AtLDwlO1NFityV12upa+sQTP5eFgKw0QztdtqZr4+SZ9ntL3Uxqh'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvimMKeMLAgjPOFo3FpilVHWk9U4cyqw/cU97rPWtS5w', 'ENCv1:Ap2hIA9vgsNSZ8Yjc27dIipQputM9RdMX2KuEBVwg3EliKM6dATG3KAe', 'ENCv1:AqfwiKCqtLKSNKtIt12JRqLVt21SQwy13XUjv1QPkcJBcKT9RPT3cglDfrNOQTaNLp2S5Sta06ur/4rXeOfx66abByz3Sw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmlvpywV+NAIdpciLGvkJ95Q6vZfiKt/+Zr6vwO014fQdWP4ElvcoY4='
  AND c.telefono = 'ENCv1:Ag4K81A0wMFZfO2Z+7G1Dwt1g8DMG0nUFa8vvwkZMesUcYafrlGr'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AnHjpeSDlu3jXdXC6XxWjVoJJO8rxdpHrs+2M9O5PNah', 'ENCv1:AsAMxbpAQBwCRXC7NFi7p+0+vLRVQ3ibMXoG8lIhfp4DPRWt9XGs0eIa', 'ENCv1:Anfglhc7ICNEVn+OkCLWrq3Mpn0K1HDaTshKhHesMxjsvZkh4kR8f+kCklENoekcTWK8BOHahi1JMdvR/4IFrcgXmg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aq/tIsXYm3qRCI2Q0/oCwLWnHyqHuiuXwS4eWTPVlAdigH0OqttmPE4AaH7fd9daG1M='
  AND c.telefono = 'ENCv1:AlyXV97V5Svw38/yncc7NAF7ey7sOUIZbtEuv+rbmDqVUGZiJhTk'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ApFeBWE1W83f24KGFTkpXgCq9WmaG9HsiWIJ1lD4yWGi', 'ENCv1:At36R/kaDlnceexVJQpxi0Haa96T7vb3KL+GFjhkc0yK3R6qrpUDu8iI', 'ENCv1:AunHSkPyXDmELJshJzt2lne4svuKUCqHL9Q9NEHuJEhnj5htfNuiVNZLqzJyH8I9xrILWDNBxiL2Gzb9xmWqQh1u5bOgJQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ajoo3ep48KFgb12xP1ZHXP/81YHvYQvzU/R+hAINV56RauTHkVcNnr4Y'
  AND c.telefono = 'ENCv1:AmxCRUeOBJap50moaZZ4du34wPvu18IXHddq/la0x8DAA8Z9lVVc'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:As6CKjVTX38uoEvGz0oT4l2dFtB8pFuO6lVcGsRf9ODW', 'ENCv1:AgmvA0rWdFyZxWxSfTjE2Ucb+KJz8GuQt5kPHD29wzBnBwQptuW8PLCR', 'ENCv1:AuwwscB5g1V3wXXL9k10TkG8Hc3k+cc9wgClQnFZYFEc3r94IGr9cbBJCGdLLaI2JyBhQaxMvJOTC5PrtKu97wHZSZO6gg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ak5CjkAqvXKudPmLczRe5jl30NG1EJeNhz4pPYAZ1j8x2zl0EYJX4Pr+W3pdM9RgYw=='
  AND c.telefono = 'ENCv1:AuniDAeLxkEjuqPpP16o8YAIbkAPBQrdIlUO8SNvETwltyUSc9Eq'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AkktqcIWwhVXn38KUTQUPjabhJ5TOBdJW8JoDVqMfOZo', 'ENCv1:Ap+31Omax24qjaN27ftiKx6b9OfTPTSksLLDCDiNWccxd2uDwn7znXRv', 'ENCv1:AjmPBm2ktBDnK9QcNE6PMdhi+AEPwcnMQsDXNoD6COpGSAAyJdpz9Abu3xoQXmY/NY97GBZ1GpnWPpYopw7gOE8I4sxhUA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvIYs9AstGiaOVOSrh9JaEPgALeME2gTDtnmqRLHpDKpXSTdYdmz9WU9RbrpwQ=='
  AND c.telefono = 'ENCv1:Ah/KeG9djA18okX8mSE/OeDFxOjOyrKEvGC1hifpl0/p9OFXT1uO'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvOdvYWyYA4dbGvGH8+6BvuVDz2VrRKVGGlIqgGM4HcO', 'ENCv1:AtC7MVaDhanMb3inO91zCoZtXng58WI3aFQutdnxwe1xKNpK+FVlDj7u', 'ENCv1:Alg87d8dWRcMmqByqUBPKSOqF7Z6ILL3HKYV803PPMaGeu7lHNx6jBs0+garh8evf4qDo4OZm/ewvjdWUkTdn+shjQJeTQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AiNmixuPW3FN0ApZD8b8DtMaxW0QUh0rB6HInQmXboxDFpbmgGJWMiJeuQ=='
  AND c.telefono = 'ENCv1:AoaB1MEpITWRskeGy7an6FKgZr2ZKjjMWe0ExulPP2u9m/zm6n2h'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AskaIypGWMqJgxWD4LQPH7etuDpEu1ald0ivzik66I1P', 'ENCv1:AlnOLWcq0NAZF8jMke6JJvc1MekDGCTAzNF6eYDQT7nkNN/djGMgV8gc', 'ENCv1:AiLSWCi7u5HxYXyi65qyZo64O9V3Hopn7RXKyDSy22cqCxN3Nyrj8jpKjpATsG8MTdUhyQd+0U/+mjRQC6ilHKA2pXjSvw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AhkZW0nwCz9+X6bjNV1qiUuwaeZtVOy4t/fDSlonHdgYSkdbAU80JSJ+Mzs/dw+M'
  AND c.telefono = 'ENCv1:AqFmr+K3IH+7lC6uKSCerfIrHfkOyOOW+8UpVL1qqWZfg/AyCfoK'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AuBO10QK8dmBiH4hzc9yiB+lgnvDysO1MaRG1F0/uVZ1', 'ENCv1:AiUsGgKsdkoLM/SwfTsp+WAK3IiK0cib5wawb8STxkcRvgff14/+R+So', 'ENCv1:Aoc/E9gtETN+Itks+Q6S++NHT0ZRf/PnDcmlXUoY0qjfAji0M4y/1fW2geD+wdEbYjfEQkoQEs0EBfZNKA8SBRXYPYH3+A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AifSGV1lnoHeENQrhSZSj3f6Pn58Fovf/D+eIxAfCjKFZlG3EOyFq8bF58/dDg=='
  AND c.telefono = 'ENCv1:AgWkz1EFiWBYkNawL6JMBD6irSayaqFPf2VQis5Tq5Epvfh/8xdq'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ArLcG4IpQ4j0WNoU4RHviyaQV4i1E74vT5kEO28KTLMs', 'ENCv1:Aq7P8EC7KNXoQ/ahXkBE+snvtLnPN0nY2cH7Dp7pWPWtlzx5KR5QjeHk', 'ENCv1:AhXKVePKHwO3hJEPe51FOlbl8jf/wf6VrwcHaMnlkd8JIvYuyL/cEJFySA8SEZsohJarYut4sgewV1FX1ujxL64SRP77MA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AgTSaFV9RNIErfKwBgSVzGY/ecIA515zjZvmRWk57DoDlg9sk7P5'
  AND c.telefono = 'ENCv1:AtLgKcQvzpw8Xb/vxjPylND4nHGawbDw9TUSkVlmrY/ifnX1bXL+'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AhogR3VOWoCKb9sB7Xw3r8NYkSL75Pzgtnw+az1bmlNz', 'ENCv1:Am055dhUjglzOWkip+l40VhrQ5ExorksWVJbVxuERwx9NWyeZ4tIyXzg', 'ENCv1:AnW8Yarfm/ceCOgoC5RDln1spopSJKVomtwYXdQKGgTNnjiIq7j4Ym30m/6ghI3+9GHA2wCPR4kZH06JAt0xaWhfZDc5Gw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AiOz/oqTUcikdWNqo0nFdNydshYXKfQo5/W9yLACxVlbcGOmJbu8qmndfhp9blpFtG6ClZc='
  AND c.telefono = 'ENCv1:AhpFwhAuzRb8/4w9ktzs266kUqGt3xZD56pxbRLUPIPQLEC/JpZj'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AsUQzn7jzmr2p8iJrC8QmTaYbjTkVwnmIm7q6shtrkxf', 'ENCv1:AqVWGRt6zu2nUNYQ+3dFmYCQD/aDDSRMMuXoXGdaUT1XCnv6tCe9QqV+', 'ENCv1:AuY2hBkemjLvvgz/LnrTGD7sTxH80wKXJ/B5sKUYFrQlyRjhVxtY4BRQJFZkU9zmWVS11FrcBsnR06x/LhnBGec2mSm63A==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aox8A2zo/kQMF+2dq9nYFz1bPcQFwEAfkRl0Qxrv8t9CMCh1HNk11SSjIxr1ObRLjfVLYTyR1y5y+g=='
  AND c.telefono = 'ENCv1:AiGmzKjxZiOSGvviz2BIqOv7ksoEiqr4qvXH+kcUiBozb4XriwKN'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ag5noAwYFS+3hyZ+m6E9qY3sUNRKH4acQ1kcaqR0TxW+', 'ENCv1:ApIVxyr8FZRqDTv0L6j2pR76Wg99jE255EXEMgAU0r1xExXpl2O+HXoZ', 'ENCv1:Ap9/1TzVLybl/kyj6yeKvbvKZqWCPKiqWcpE/ZV3A5rr2ggpdNo2WN8sXPotpjfzhJcGIlIpVfTOxK47mRsolviFkilySg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AjTISvIVWucbHSSIW71KkstO6NG0+YUVwObxEGQ/EyhPyshAJHS5kqkV'
  AND c.telefono = 'ENCv1:Ag0EY5q3Y5+yccwUxRLTbDJ0eDZR3ON2euS5jvyEMzffbQkDveBd'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ase2i7hAkZxAEyLuXtTg6qvjrQoziQoJttJqFdkMVIPH', 'ENCv1:AoQ3CdKYLJKATq8cINZZwqOgWJVGjVY6rkKxB9hVuOdBWH0ieAv2bG3p', 'ENCv1:AufmyeTIU7gXj/tjehD/V7jPF/GCba3NbtGJTkpz618xavgLAKNVOJq1Px5JB+WCl5gJkSMCkfAVpS9ZbY1wkfn11IX2hQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:As24FpRbJcv37/Tp1ufUrxY1fhuVMe6p477y+lH9okYJgkhyjaaD'
  AND c.telefono = 'ENCv1:AqVAYha4g906N9Nnp7cd+dVI6cxzuhJpXk6VR7+B9glDN9hL3r++'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Amq/9y7orIPqoCjzrQ8SBdXQj3cgImopKiXOqQnA9IY4', 'ENCv1:AvRZCYEeZWScahi+gesWExIHrcbn+TjOiterAvogrQgoNYAxUmafp2s3', 'ENCv1:AryYu62YlkxRVX7+Aj++ZOl5y4rBjSMm1eAj5YROn15xstNFAbxwywyIFBj8NhHavSv/MQRVGQ7ERTVwopGULL2XVKjTfA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnJ5S7MfGEKFONjaMVZc9i6+6CtCbA0sw0vgg1NGtpvk1ZlTtdI/iA=='
  AND c.telefono = 'ENCv1:Aqwq49QIQfA5bph79USQKXkRIA9cHRpWZwUwYym4Voka+BlIZ8wk'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AjYDhyEaXvmcPgCJdLwxBXVXcztUFkPKMpMlEYGA7IVv', 'ENCv1:AszATIprwXbUA48FUZzZVUXloZkPBG3mkPHRJrtQWS26aLruqtmNsY6Q', 'ENCv1:Aifrl5t+u1L7CGhOuYTc+QWu6dFvoAudaWZc2o2BSHMj8G0FaQBdPP16dKhhoVmaMAkqNbi4pxHmvNT84v9WTDdY/QMdzg==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvZV6IkpD98Vc4G11gmndVejUoxctSpRACXn/ZiyraQx6CRnnOQk'
  AND c.telefono = 'ENCv1:All4Le5r+0fFMlJSi6Zh6l+QePMoSjMpJHCn7Vlswp5Bw5j18BNS'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvJh7c3x0f36bkptVpt/Spz07ZXvB50Wpu+J7Ab8r9x0', 'ENCv1:AsAdrdKD0HgJDWQ2DHrDxgEtFUwkclJFw5dtGeOsLDsLyn138e1NQpnr', 'ENCv1:AlVAbQqIAxNW35RNIyXgbmc5fcEGJhcp3uRz4nfDOUMV52qrd4BVac/3/6qLYasM2pUh5dwaMW5k0rlZOhqTvvWTGK56UA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AulEUsCITKQdU1IPl/UhWd9iNdO9ZgEfdAmr5r4EDoCi4BZ/UhM='
  AND c.telefono = 'ENCv1:AhzUGZlggtcFPgx7K2SoTMgMmHnbMWQVnRMManVG/ptnaPMdKlb1'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ape6iZAD1Oy8V+0AaG4niPGmyfZlyvucGsmyrTkWm2GO', 'ENCv1:AhfW2nAQFTi5MS3X8jrw9gmO0ng9Qdut4Y5YeNP2dyvYJjUSIdNP0OtG', 'ENCv1:AoUrVxRdpKrB2jzo83tbzVaWiom6obviA+Vq0L1KpKu8RK4GgBIqvJ5LfLnSF/edCIVDkmZQ+eWMXEv2dPBFTex6CUBdMw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:As31aP/7+E+UbKpuEt+Mw3ZgIAnjGTY4XdBdP3I4sbPpGHGiRa0Ff+62'
  AND c.telefono = 'ENCv1:AsRih52pZcsDA/n/cbaus6fEAB78RFG0PA9b0BtTGIAJHKTOisb3'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ApPJFDKG2ewkxLBhTpcfuvgynEoCrwZXWDpNkp+fnZJm', 'ENCv1:AlEhde1yPWDiB/torHlCVj3qsS5mr5de54/FQMKVSRLCf04MXmBSYJVr', 'ENCv1:ApwXVyuBbchF/j7Hh/985UtaacTytIvawnfMIfvSzEUq1rwUXtEPFyxaxczmRn53WGL9cZE1GAeHSA3LowO67jnFNcWD3w==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AnxlvHbqX+5xoVPFRictblYOcz83pdbk8r208eYT3/xXBjXd481W'
  AND c.telefono = 'ENCv1:AjIVdp1wY9C6XSTw+wEU1REddHXHFGpYmv/ZWwtSXRB3ZLoskpdy'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Aqgh2KQpNWDg52VcY+ghmfXtd4yl44k/tt0YNHTsDV02', 'ENCv1:As6dqu/7iWf+vBm6WPTODtwmHvplytufxOQDnlqBiCwtH3fNZ0bq6xrT', 'ENCv1:AshYLbD5ueSOJrxZ+ya0L0Bi2ahh3dyZBgKTPQNzbzpJ3ILQeXU8ibWfjOZ3uY7c/v95OXVU1UQgP3NLPiOwV0aWBL0m9g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AgPqhxyPoNt24fz7/3eHVMI/l8ryVOG61L5zJuG4gLrsmnHiPk6ceQ=='
  AND c.telefono = 'ENCv1:AmvYjGVFlVTd0kmn7HGJ6PBWa6BcKB4gF3lKf9rP7iqFA3xnmWCO'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AnVI46O8NXb/ENRI0vjTkYe0YJH8ss7FzMGgQrKO99mG', 'ENCv1:Ai8itm0EwD8y7e4sjaZwfxaX6lUS3rGm9mLvt2jNOEa32aVrh1aXODaZ', 'ENCv1:Ajp5x8bEqk8NVnrqzcf1DwjvvyPl6XJ7XquZgbxkVaifpzeYslIA9mJOyiNFdoC2pRJrY1psMSUYlHO6fnBgsGVX1p3Vfw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvbwJmt4eRJVBIprr8SfsSF9F7Z+lAj5APVoMmMZt8Aj3HUtDkeDNtw='
  AND c.telefono = 'ENCv1:As2tjmqkatMgZ8jPeIpe975N85UqdQ2l5d7I9x8+ep3rF0/wmzXz'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AmO0phqhfoOT3oGHemLdlGhbVS4XWyZ+xLH3i6Nc7FYe', 'ENCv1:AsR5mmK/wvWl4zofemWkqHEDWOEJrlNitRH5RZjRxNta7BD5ZL5ovvZO', 'ENCv1:AlvqhPDbI9TjU/6fsu61Xm0DRIC6nLxWkMHLsy5oRvhuqg9fENqvntN993d3UHiZOlEXQecsbfyG2rMvuGEzcwqjWEBsow==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvI25H9Rra+XnDRndJ6vtqjnl7soAeZajMHhlvHekmoKmZR8pRyvMg7U'
  AND c.telefono = 'ENCv1:AvMxwHH2VmYBSeKgiM8gPapK/Q20538g4i/7vroJluLD/D5hnzg8'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Ah38E4AICftMsj1Zj6u2oX07gjFlvDbR5z6Neputx+EN', 'ENCv1:AowPVW/7czRFBFUBHPidaz6CDCsZcNYEAmKkBRGIR0xVa7acwVjDUmCM', 'ENCv1:AnOJqOXU2UdW54HDHi2Kw4mwJ0Z9RuupJS0BXXLERQJuvQ42jvKEs0DIPXT4VTmVdJ8QUh+2FhWXw4yTJIDNDljEOXicQQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Apcn1HUR00yIA5/P8VSgEtoNKucP1qYaw/33thhx3/jHUA1dv+RLpZz4vYHMKoCRkw=='
  AND c.telefono = 'ENCv1:AgpBdO9IYd/iWYQiI6kUw06+AoBFCWvhz/KqXViD2RF/3yyhNWfx'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AlZ0O1PGWCU+EA7PIpVpS11B8EJXkbKjNqYdbOjHeVk2', 'ENCv1:AvICiEC5is/rm2hoCUIcHa26iVyYVWtTMacTvkq5O9XeJLUyriwtcrzS', 'ENCv1:AqqGIFTCi4dOvapOMX8tMuf+7KbsvcHqxwI1eqhS6kK/ccnqRvj2iEOfzj6SckLIXl6gezBJ9ZY/GFiwp1huTBEzOdN13g==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AmOIoZPZaxkJ4J+UDZ1IFpT4l1r4Eq8StYlqEHpxXplRRlsAIOv5/zc='
  AND c.telefono = 'ENCv1:AhPq+E/7sVEZPjJcp0K9NAfQZCB+7qoydQ7e5ztNSJUmr3RM6EfD'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Aoa8fGF7hk6Xzt4CLXtmd661vJ1MQGLP0L2YMWgXr67/', 'ENCv1:ApqUi12v/85UIPMqSXxseF+e+gY6rjto5cswSZv3YrlkrUowCFZfdJ6w', 'ENCv1:ApzrBtf71qcN+Tt+MpQ9tpl+o6nNtPN8zAlVHQIkWOqGm0JvhhOa084zdpYxupFRsOhRLkTvZjkCK5HBYBi/nwi7FgXsPw==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AjPGUQIaCcvANpGRCQhXzDm0WD4FIYFmjZ7Rwmde/5DBO1VD8NN6WRu3gz8UYInCRZPa'
  AND c.telefono = 'ENCv1:AhoBOMYvXQiXd5lcQadFuAthropWIs3SopiutDd6TGQfBvHyFjGe'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AvGabmgkuUniJJLbTkjf9j2+D5ka7y3OYTgbzI+pofXw', 'ENCv1:AhWPCKfPkrhXyKLgcoIO43Lw9jYNxPZ45YjKd9bB6vrITOUd1HcF7MNv', 'ENCv1:Aq3elYTZJXYAm8sca/Q/mw/j2UzQhZ/cIiIcmeqPk/96SONESQ0n91GfrgF2hVNIOKLeFBvp4xlco0soZv0KAoKvcLCiAA==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Aq/3ygpHTgtLeASkHxNGxgpBsvugGy9kkMfLPVLGlvMQA256xwE='
  AND c.telefono = 'ENCv1:Aj0gNfN0vnKdlplqNivcgVY7RwBtEpbjBOuwgmOysFrLy4aiRTYA'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AuK4CLVLhUOe+anuoQPnYHEaUm79Dk6H6jCUBLsIDzsU', 'ENCv1:AkYjdKwvbRabuHfE1DiMKQonkz0l3YUMEuHTYnECaVEtLTlH/ecnSMie', 'ENCv1:At3tRadPLmesMLQv7AJ/RRCG6emRmKQfy2xiVPRKKb73i5DiPacWCCdihwMH10FpVGvk3fuhCqFy7wB1Xf/KCime0EVtGQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Ao6PlFz0Fv3p/taB5/8w4hnoAuRPjDvCMEGdES77/RbLbs3nwaT0F6jN'
  AND c.telefono = 'ENCv1:Ahen0SFUebSYYm4m2Yhn+vBgVx8j6ulc3U/0WfLspH2D27iV3Hre'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AqWSymJwvbf2DlFwzqcByCzS5XYUTfxymY0Qk5Us2BRd', 'ENCv1:AuRZDLFRAgJydOfXP6Ky0kMcZjqqr1GR4miQ4GbXCdu21Q6G7jF3DBVJ', 'ENCv1:AqedifEoWjp76GEhaxlSbHMxsMs8vSgnhQaX9Z/u1GVYT0dni7azcUTdoUU3haOcmlgQi3AbZgj2eREApoyLmhjrZng/lQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:Atycpqr0nmZtOEFbjnhoKGaFtANrFLTbIS+ApxRTrAgxwJyvp6Kzl6E='
  AND c.telefono = 'ENCv1:ApcEJG2bS1XirD86NvtecXp6pIaGLRLU9ZTP7BKSicRm91qpM+6z'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:AgKxfv676mF1SuzaozwSdCl+6CRDiAFTZcLMPUAoCM7b', 'ENCv1:Aoou0wNJr46lG6EvrQtDI85QFFv2spej3fbgypK2v6xYhWB6E9eej68T', 'ENCv1:Amb7THEirfGaz2V62SLKg4t+BH31E2G+gfT7iEdJkk9R/a+ageMKZCwNCxwUyv757umsaKy2OHmxuw52hniJMAoyQLHZrQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AvD4EIGUGadcwag2Ifjzx9dKRBw7jCh1ulc/Uejk8a36Hs4KUtdlRJsEIxdge8+PtB8='
  AND c.telefono = 'ENCv1:AhXDNJPonn5tlquoOzKpDk6e7UW397m0tuUnGLuaKY0YkJNe1cJP'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:ApYpO6/WU7ASBHLVVybzu7mr+xv4D7Xijj9ONoD9ai/W', 'ENCv1:Avox2OmibBETqJYn+TzgXu7pRSNXVFjcdjyYdOEEfDmqlDPvoXnIvyAn', 'ENCv1:Aj4I9AlGyPzUdhLHCIUWlJozZH+/GZkiDBccGYmC0FDnqsS9WE1nU688nyOr0PByC6Kwlf79qRdTgoegigTbpTL2Ltue8Q==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AsiZLS/OLVP0Vs7gKW+blq4EOaOmRrIRzWOt9Y0S57eALy339Z19jw=='
  AND c.telefono = 'ENCv1:Aualtbl04WWNkgL8bipRCP8riqH4FUdEYN8CtKQ9HWbLFngOX55Y'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default)
SELECT c.id_cliente, 'ENCv1:Av9ODXtQNNTujkuk5wWbea2i6rH65irJX7V7blNMOsAu', 'ENCv1:AtLIX05yYHudVODZj/1M90LZ2CODzmtLnEstZvreeu0EArWJmVY7rQX4', 'ENCv1:Aj0awfsbX0cl/fyvFc+T5JJszERJv6XDpEZkOrlHLZOJq9zGtajOgWApBmWqLcAoLX52W02Cfe6sryF1ZSlKlXgDwVyXKQ==', 1
FROM clientes c
LEFT JOIN cliente_direcciones cd ON cd.id_cliente = c.id_cliente AND cd.es_default = 1
WHERE c.id_usuario IS NULL
  AND c.nombre = 'ENCv1:AotDT8NYO+soTZ7D12anUv/h5CqmrJKJHmQvWawh1gV/lpTXK8S6'
  AND c.telefono = 'ENCv1:AiTJ7jFcsCFh+7imA3SVJRsMUdQtU72CrL2GgVjHkk9hU0xDWRiu'
  AND c.fecha_creacion = '2026-07-09 13:23:10'
  AND cd.id_direccion IS NULL
LIMIT 1;

COMMIT;