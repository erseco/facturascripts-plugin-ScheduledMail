# Manual de ScheduledMail

ScheduledMail añade el **envío programado de emails** al formulario de envío estándar de
FacturaScripts. Si dejas el campo de fecha vacío, el email se envía al instante como siempre.
Si eliges una fecha y hora futura, el email se guarda y lo entrega la **cola de trabajos**
(cron) cuando llega el momento.

---

## Requisitos

- FacturaScripts **2025** o superior.
- PHP **8.1** o superior.
- **El cron / la cola de trabajos debe estar configurado y en ejecución.** Sin él, los emails
  programados quedan en estado *pendiente* y no se envían nunca.
- La configuración de **SMTP** (Administrador → Email) debe estar operativa: el envío
  programado usa exactamente la misma configuración que el envío inmediato.

---

## Instalación

1. Descarga el plugin desde la forja o desde la página de *Releases* del repositorio.
2. En FacturaScripts ve a **Panel de Admin → Plugins**.
3. Sube el ZIP y pulsa **Activar**. La tabla `scheduled_mails` se crea automáticamente.
4. Comprueba que el cron está configurado (ver más abajo) y que el SMTP funciona.

### Configurar el cron

Los emails programados los entrega la cola de trabajos de FacturaScripts, que se procesa al
ejecutar el cron. Añade una tarea programada en tu servidor, por ejemplo cada 5 minutos:

```
*/5 * * * * cd /ruta/a/facturascripts && php cron.php
```

Cuanto más a menudo se ejecute el cron, más cerca de la hora exacta se entregarán los emails.

---

## Uso paso a paso

1. Abre un documento (por ejemplo una **factura de cliente**) y pulsa **Enviar email**.
2. Redacta o revisa el destinatario, el asunto y el cuerpo como haces normalmente.
3. Localiza el campo **Programar envío** (con un icono de reloj), debajo de los adjuntos:
   - **Déjalo vacío** para enviar el email inmediatamente (comportamiento de siempre).
   - **Elige una fecha y hora futura** para programarlo. El botón de enviar cambia a
     **Programar envío** (de color distinto y con icono de reloj) y aparece un aviso
     indicando que el email no se enviará en ese momento.
4. Pulsa **Programar envío**. Verás un mensaje de confirmación. El email **no** se envía aún.
5. Cuando llega la fecha/hora y el cron ejecuta la cola de trabajos, el email se entrega con
   sus adjuntos y el documento relacionado se marca como enviado.

### Límite de 30 días

Solo puedes programar un email con un **máximo de 30 días** de antelación. Si eliges una fecha
más lejana, o una fecha pasada, el sistema muestra un error y no programa el email. Este límite
se valida tanto en el navegador como en el servidor.

---

## Gestionar los emails programados

En **Panel de Admin → Emails programados** tienes la lista de todos los envíos programados con
su estado:

- **Pendiente**: en espera de su fecha/hora.
- **Enviado**: entregado correctamente.
- **Fallido**: hubo un error al enviar (consulta la columna *error*).
- **Cancelado**: cancelado manualmente antes de enviarse.

Puedes filtrar por estado y por fecha, y **cancelar** los envíos que aún estén pendientes
(marca las filas y pulsa *Cancelar*). Al cancelar se eliminan también sus archivos adjuntos
guardados.

---

## Adjuntos

Para que la programación sea segura hasta 30 días, el PDF del documento y los archivos que
subas se **copian** a una carpeta propia del plugin (`MyFiles/ScheduledMail/<id>/`) en el
momento de programar. Así siguen disponibles aunque pasen varios días. La carpeta se elimina
automáticamente tras un envío correcto o al cancelar el email.

---

## Zona horaria

La fecha/hora que eliges se interpreta en la **zona horaria de la aplicación/servidor**
(`FS_TIMEZONE`), que es la que usa FacturaScripts internamente. La validación de «debe ser
futura» y del límite de 30 días se hace en el servidor contra el reloj del servidor.

---

## Solución de problemas

- **No se envían los emails programados** → el cron / la cola de trabajos no se está
  ejecutando. Configúralo (ver *Configurar el cron*). Los pendientes se entregan en cuanto la
  cola pase su hora.
- **Un email aparece como *Fallido*** → revisa la columna *error* en *Emails programados*.
  Casi siempre es la configuración SMTP. Corrígela y vuelve a programar el email.
- **Falta un adjunto al enviarse** → comprueba que existe la carpeta
  `MyFiles/ScheduledMail/<id>/`. Los archivos solo se borran tras un envío correcto.
- **El SMTP no funciona** → el envío programado usa la misma configuración que el envío normal.
  Si el envío inmediato funciona, el programado también.

---

## Preguntas frecuentes

**¿Cambia algo si no programo nada?**
No. Si dejas el campo de fecha vacío, el email se envía exactamente como hasta ahora.

**¿Se reintenta un envío fallido?**
No automáticamente. Un email fallido se marca como *Fallido* y debes volver a programarlo.

**¿Cuándo se marca el documento como enviado?**
Solo **después** de que el email se entregue correctamente, no al programarlo.
