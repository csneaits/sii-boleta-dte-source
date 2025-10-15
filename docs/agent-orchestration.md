# Orquestación de agentes (Guía)

Esta guía explica cómo orquestar sesiones multi-agente para tareas de ingeniería de software (diagnóstico, parches, tests, resúmenes).

Principios básicos
- Roles claros: Coordinador, Analista, Codificador, Tester, Resumidor.
- Contratos JSON entre agentes: cada respuesta debe incluir un JSON válido con campos acordados.
- Tarea única por mensaje: cada agente recibe una responsabilidad concreta.
- Reintentos y errores: el Coordinador debe reintentar con el mismo agente ante incumplimiento del contrato antes de reasignar.
- Seguridad: no envíes secretos ni ejecutes comandos peligrosos sin mediación humana.

Formato recomendado
- Mensaje de sistema: define rol, responsabilidades, formato de salida y límites de tokens.
- Mensaje de usuario: contiene el `task_id`, contexto mínimo (archivos, comandos), y el JSON schema esperado para la respuesta.
- Mensaje de assistant: debe devolver un JSON válido y opcionalmente un resumen humano.

Contratos de ejemplo (breve)
- Analista:
  - inputs: { task_id, files[], failing_test?, description }
  - outputs: { task_id, ok, diagnosis[], repro_steps[], hypotheses[], notes }

- Codificador:
  - inputs: { task_id, files[], failing_test?, constraints }
  - outputs: { task_id, ok, patch (unified diff), files_changed[], notes }

- Tester:
  - inputs: { task_id, patch?, commands[] }
  - outputs: { task_id, ok, tests_run, tests_passed, logs, errors[] }

- Resumidor:
  - inputs: { task_id, sources[] }
  - outputs: { task_id, status, summary, artifacts[], next_steps[] }

Plantilla de flujo (coordinador)
1. Solicita diagnóstico al Analista.
2. Si diagnóstico ok, pide parche al Codificador.
3. Pasa parche al Tester y solicita ejecución de tests y logs.
4. Si tests ok, solicita resumen final al Resumidor.
5. Genera el JSON final con todos los artefactos.

Buenas prácticas
- Usa IDs de tarea únicos (ej. T2025-001).
- Limita el contexto a lo necesario para evitar que el modelo pierda foco.
- Usa JSON estricto y valida antes de ejecutar acciones automáticas.
- Mantén al humano en el loop para merges finales o ejecución de comandos con privilegios.

Ejemplos y plantillas están en la carpeta `templates/`.
