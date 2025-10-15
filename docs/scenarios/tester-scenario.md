# Escenario: Tester — verificar parche y tests

Objetivo
Verificar que un parche propuesto resuelva los tests afectados y devolver un resumen estructurado con logs.

Contexto mínimo que el Coordinador debe proporcionar (user):
- task_id: ID de la tarea (ej. T2025-002)
- files: lista de paths relevantes
- patch: unified diff (opcional) o instrucción de qué archivos cambiar
- commands: lista de comandos para ejecutar (por ejemplo el comando phpunit)

Mensaje ejemplo desde el Coordinador al Tester (user):
{
  "task_id": "T2025-002",
  "goal": "Ejecuta los tests afectados por esta patch y resume resultados",
  "patch": "(unified diff aquí, o null si ya aplicado)",
  "commands": ["./vendor/bin/phpunit --colors=never --stderr tests/Presentation/GenerateDtePageTest.php"],
  "expect_json_schema": {
    "task_id":"string",
    "ok":"boolean",
    "tests_run":"number",
    "tests_passed":"number",
    "logs":"string",
    "errors":"array"
  }
}

Respuesta esperada del Tester (assistant):
- Debe devolver JSON válido cumpliendo el schema arriba.
- `logs` debe ser un resumen de los resultados. Si hay fallos, incluir el bloque de error más relevante.

Ejemplo de respuesta (tester):
{
  "task_id":"T2025-002",
  "ok":true,
  "tests_run":12,
  "tests_passed":12,
  "logs":"All tests passed in GenerateDtePageTest; no Notices. Runtime: 2.1s",
  "errors":[]
}

Notas para el operador humano
- Si el patch no está aplicado, el Tester debe indicar pasos exactos para aplicar el patch localmente antes de ejecutar los comandos.
- Para logs largos, el Tester puede escribir el path donde dejó logs completos en disco.
