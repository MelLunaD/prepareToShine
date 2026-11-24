# Diagrama de la base de datos

Sale de `app/sql/01-esquema.sql` (que ya incluye todos los cambios hechos durante el TP). La misma imagen está en [`diagrama-bd.png`](diagrama-bd.png).

```mermaid
erDiagram
    pais ||--o{ usuario : "vive en"
    usuario ||--o{ partida : juega
    usuario ||--o{ pregunta : "crea / aprueba / modifica / da de baja"
    usuario ||--o{ reporte : "reporta / revisa"
    usuario ||--o{ solicitud_editor : "pide / revisa"
    categoria ||--o{ pregunta : agrupa
    pregunta ||--|{ respuesta : "tiene 4"
    pregunta ||--o{ partida_pregunta : "sale en"
    pregunta ||--o{ reporte : recibe
    partida ||--|{ partida_pregunta : "tiene hasta 15"
    respuesta |o--o{ partida_pregunta : "elegida"

    pais {
        int id PK
        varchar nombre
        char codigo UK "2 letras (bandera)"
    }
    usuario {
        int id PK
        varchar nombre_completo
        smallint anio_nacimiento
        enum sexo "masculino, femenino, no_informa"
        int pais_id FK
        varchar ciudad
        varchar provincia
        decimal latitud
        decimal longitud
        varchar osm_lugar "contorno de la localidad"
        varchar email UK
        varchar usuario UK
        varchar password_hash
        varchar foto
        enum rol "jugador, editor, admin"
        boolean validado
        char codigo_validacion "6 números"
        datetime codigo_vence
        int preguntas_respondidas
        int preguntas_acertadas
        datetime fecha_alta
    }
    categoria {
        int id PK
        varchar nombre
        char color
        enum fase "shine, die"
    }
    rango {
        int id PK
        enum modo "shine, die"
        varchar nombre
        int puntos_minimos "UK con modo"
        char color
        tinyint brilla
        varchar icono
    }
    pregunta {
        int id PK
        int categoria_id FK
        varchar juego
        varchar texto
        enum estado "activa, inactiva, cuarentena, revision"
        enum dificultad "facil, media, dificil"
        int veces_mostrada
        int veces_acertada
        int creada_por FK
        datetime fecha_alta
        int aprobada_por FK
        datetime fecha_aprobacion
        int modificada_por FK
        datetime fecha_modificacion
        int desactivada_por FK
        datetime fecha_desactivacion
    }
    respuesta {
        int id PK
        int pregunta_id FK
        varchar texto
        boolean es_correcta
    }
    partida {
        int id PK
        int usuario_id FK
        enum modo "shine, die"
        tinyint nivel "0 a 10"
        int puntaje
        enum estado "en_curso, terminada"
        int almas "solo Die"
        enum almas_estado "ninguna, esperando, recuperadas, perdidas"
        datetime fecha_inicio
        datetime fecha_fin
    }
    partida_pregunta {
        int id PK
        int partida_id FK
        int pregunta_id FK
        int respuesta_id FK "NULL si no respondió"
        tinyint numero "UK con partida_id"
        boolean acerto
        smallint puntos
        datetime fecha_mostrada "controla los 30 s"
    }
    reporte {
        int id PK
        int pregunta_id FK
        int reportado_por FK
        int revisado_por FK
        datetime fecha_revision
        enum motivo "9 motivos fijos"
        enum estado "pendiente, aprobado, rechazado"
        datetime fecha
    }
    solicitud_editor {
        int id PK
        int usuario_id FK
        enum estado "pendiente, aprobada, rechazada"
        datetime fecha
        int revisada_por FK
        datetime fecha_revision
    }
```

## Notas

- `rango` no tiene claves foráneas: el rango de cada jugador se calcula con sus puntos de la semana en cada modo.
- Los rankings (semanal, histórico y cementerio) tampoco se guardan: se calculan a partir de `partida`.
- `reporte` tiene `UNIQUE (pregunta_id, reportado_por)`: cada jugador reporta una misma pregunta una sola vez.
- `pregunta.estado`: `revision` = sugerida por un jugador, `cuarentena` = reportada, `inactiva` = dada de baja.
- El usuario con id 1 es `sistema`, autor de las 1500 preguntas iniciales (no puede iniciar sesión).
