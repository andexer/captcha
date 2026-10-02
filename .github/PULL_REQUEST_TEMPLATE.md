## Qué cambia

<!-- Qué hace ahora que no hacía antes, en una frase. -->

## Por qué

<!-- El problema que resuelve. Si cierra una incidencia, #123 al principio. -->

## Verificación

- [ ] `composer check` en verde (suite + estilo + PHPStan nivel 9)
- [ ] Añadí o ajusté tests que fallarían sin este cambio
- [ ] `CHANGELOG.md` actualizado en `[No publicado]` si el comportamiento observable cambia
- [ ] Regeneré lo derivado si toqué su fuente: `composer assets:min` (assets) o `composer config:templates` (plantillas de `install`)

<!-- Si el cambio afecta a lo que recibe quien instala, cuéntalo: la puerta de
     instalación limpia de CI no lo detecta todo y tú sí lo sabes. -->