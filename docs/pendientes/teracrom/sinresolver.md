# Teracrom: sin resolver

> Pendientes · actualizado 17/11/2026 · Solo lo abierto (sin resolver y parciales).

---

## 1. Resumen

| Estado | Cantidad |
|---|---|
| ✅ Resuelto | 0 |
| ⚠️ Parcial | 0 |
| ❌ Sin resolver | 8 |

Detalle y evidencia: `docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md`.

---

## 2. Definiciones del sistema

| Prioridad | Pregunta | Explicación | Respuesta / Recomendación | Estado |
|---|---|---|---|---|
| 🔴 Alta | **TE-01** · ¿Cómo se registra "por qué sale" una persona? | El pedido lo menciona, pero el sistema solo guarda entrada, comida y salida | Opciones: catálogo de motivos que captura un supervisor en la web, o teclas de estado configuradas en la terminal. Recomendación: catálogo en la web, porque no depende del firmware | ❌ Sin resolver |
| 🟡 Media | **TE-05** · ¿Quiénes usarán el sistema y qué puede hacer cada uno? | Necesario para SE-01 y SE-02 | Propuesta: administración (todo) y consulta (dashboard y kardex) | ❌ Sin resolver |
| 🟢 Baja | **TE-07** · ¿Hay turnos nocturnos que cruzan la medianoche? | Define si hace falta SE-11 | Si los hay, se corrige el cálculo antes de producción | ❌ Sin resolver |
| 🟢 Baja | **TE-08** · ¿Cuánto tiempo se conservan las checadas? | Define la retención de OP-08 | Recomendación: conservar al menos el periodo que pida el área legal o de nómina | ❌ Sin resolver |

---

## 3. Legal y licencias

| Prioridad | Pregunta | Explicación | Respuesta / Recomendación | Estado |
|---|---|---|---|---|
| 🔴 Alta | **TE-02** · ¿Quién redacta el aviso de privacidad y cómo se recaba el consentimiento para la huella? | La huella es un dato biométrico (sensible); RFC, CURP y NSS son datos personales (LFPDPPP 2025) | Aviso de privacidad antes de recabar datos y consentimiento expreso por escrito para la huella | ❌ Sin resolver |
| 🟡 Media | **TE-03** · ¿Hay licencia de Wijmo? | Hoy corre en modo evaluación con marca de agua sobre la cabecera | Si no hay licencia, cambiar a una librería libre antes de producción | ❌ Sin resolver |

---

## 4. Infraestructura

| Prioridad | Pregunta | Explicación | Respuesta / Recomendación | Estado |
|---|---|---|---|---|
| 🟡 Media | **TE-04** · Datos de la terminal: IP real, usuario de servicio y si permite HTTPS | Necesario para probar con el aparato y para SE-06 | Usuario propio para el sistema (no `admin`) y HTTPS si el firmware lo permite | ❌ Sin resolver |
| 🟡 Media | **TE-06** · ¿En qué servidor se publica? | Debe estar en la misma red que la terminal. Necesario para OP-09 | Servidor en la red de la terminal con HTTPS | ❌ Sin resolver |
