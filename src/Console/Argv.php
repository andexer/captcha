<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Lectura de la línea de comandos.
 *
 * El parser es estricto a propósito, y esa estrictez es el motivo de existir
 * como clase aparte. Un parser indulgente parece más amable pero desplaza el
 * error: si `--framwork=laravel` se ignora en silencio, quien lo escribió
 * recibe un config distinto del que pidió y el síntoma —un fichero en el
 * sitio equivocado— aparece a distancia, en la aplicación y sin relación
 * visible con la causa. Por eso aquí una opción desconocida, un valor
 * ausente o un argumento de más son un error de Immediate, nunca un valor
 * por defecto.
 *
 * Reglas que resuelve, y que antes vivían repartidas por el bin:
 *
 *   - La opción acepta las dos formas, `--framework=laravel` y
 *     `--framework laravel`, y la corta igual: `-f laravel`.
 *   - Antes del comando solo se admiten banderas globales. Las opciones con
 *     valor son específicas de cada comando, así que aceptarlas antes solo
 *     serviría para que el nombre del comando acabara consumiéndose como
 *     valor de una opción.
 *   - `--` termina las opciones: lo que venga detrás es argumento, aunque
 *     empiece por guion.
 *   - Una bandera con valor (`--strict=algo`) es un error, no un
 *     desconocido: la opción existe y su uso no.
 *   - Un valor ausente se denuncia nombrando la opción, en vez de tragarse
 *     el token siguiente, que es lo que hace un parser ingenuo.
 *
 * @internal
 */
final readonly class Argv
{
    /**
     * Distancia de edición máxima para sospechar de un "querrías decir".
     *
     * Tres toleran un tecleo de una o dos teclas sin convertir cada
     * opción inventada en una sugerencia inútil.
     */
    private const SUGGESTION_DISTANCE = 3;

    /**
     * @param Command|null $command Comando resuelto, o null si no se escribió ninguno.
     * @param array<string, string|bool> $options Opciones ya resueltas a su valor.
     * @param list<string> $arguments Argumentos posicionales, en orden de aparición.
     */
    private function __construct(
        public ?Command $command,
        public array $options,
        public array $arguments,
    ) {}

    /**
     * @param list<string> $tokens argv completo, incluido el nombre del script en el índice 0.
     *
     * @throws ConsoleException Cuando la línea no tiene sentido: opción
     *                          desconocida, valor ausente, argumento de más
     *                          o comando inexistente.
     */
    public static function parse(array $tokens): self
    {
        [$cabeza, $cola] = self::splitAtTerminator($tokens);
        [$command, $options, $arguments] = self::readHead($cabeza);

        return new self($command, $options, self::addTail($cola, $command, $arguments));
    }

    /**
     * Trocea argv en la parte que admite opciones y la cola que ya solo
     * admite argumentos. El terminador no pasa a ninguna de las dos.
     *
     * @param list<string> $tokens
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function splitAtTerminator(array $tokens): array
    {
        $corte = array_search('--', $tokens, true);

        if ($corte === false) {
            return [$tokens, []];
        }

        return [array_slice($tokens, 0, $corte), array_slice($tokens, $corte + 1)];
    }

    /**
     * Lee la parte de argv anterior al terminador: comandos, opciones y
     * argumentos posicionales, en el orden en que aparecen.
     *
     * @param list<string> $tokens argv completo hasta el terminador.
     *
     * @throws ConsoleException Cuando la línea no tiene sentido: opción
     *                          desconocida, valor ausente, argumento de más
     *                          o comando inexistente.
     *
     * @return array{0: Command|null, 1: array<string, string|bool>, 2: list<string>}
     */
    private static function readHead(array $tokens): array
    {
        $estado = self::estadoVacio();
        $admitidas = Registry::globalOptions();

        for ($i = 1; $i < count($tokens); $i++) {
            $i = self::advanceToken($tokens, $i, $estado, $admitidas);
        }

        return [$estado['command'], $estado['options'], $estado['arguments']];
    }

    /**
     * @return array{options: array<string, string|bool>, arguments: list<string>, command: ?Command}
     */
    private static function estadoVacio(): array
    {
        return ['options' => [], 'arguments' => [], 'command' => null];
    }

    /**
     * Un token posicional se acumula como argumento; el resto se resuelve
     * contra las opciones global y las del comando ya conocido.
     *
     * @param list<string> $tokens
     * @param array{options: array<string, string|bool>, arguments: list<string>, command: ?Command} $estado
     * @param list<Option> $admitidas
     */
    private static function advanceToken(array $tokens, int $i, array &$estado, array $admitidas): int
    {
        if (!self::looksLikeOption($tokens[$i])) {
            $estado['command'] = self::addPositional($tokens[$i], $estado['command'], $estado['arguments']);

            return $i;
        }

        return self::consumeOption($tokens, $i, $estado['command'], $admitidas, $estado['options']);
    }

    /**
     * Coloca un token posicional: el primero es el comando y el resto, sus
     * argumentos.
     *
     * @param list<string> $arguments
     *
     * @throws ConsoleException Cuando el token no nombra ningún comando.
     */
    private static function addPositional(string $token, ?Command $command, array &$arguments): Command
    {
        if ($command !== null) {
            $arguments[] = $token;

            return $command;
        }

        return self::resolveCommand($token);
    }

    /**
     * Lee la opción de la posición $index y la deja en $options.
     *
     * @param list<string> $tokens
     * @param list<Option> $admitidas
     * @param array<string, string|bool> $options
     *
     * @return int El índice del primer token sin consumir.
     */
    private static function consumeOption(array $tokens, int $index, ?Command $command, array $admitidas, array &$options): int
    {
        $leida = self::readOption($tokens, $index, $command, $admitidas);
        $options[$leida['name']] = $leida['value'];

        /*
        *  La opción con valor desnudo se ha tragado el token siguiente, así
        *  que el bucle debe saltar por encima de él.
        */
        return $index + $leida['consumed'];
    }

    /**
     * Lo que va detrás del terminador es argumento aunque empiece por guion.
     *
     * @param list<string> $cola
     * @param list<string> $arguments
     *
     * @throws ConsoleException Cuando la cola nombra un comando inexistente.
     *
     * @return list<string>
     */
    private static function addTail(array $cola, ?Command &$command, array $arguments): array
    {
        foreach ($cola as $token) {
            $command = self::addPositional($token, $command, $arguments);
        }

        return $arguments;
    }

    /**
     * Interpreta el token de la posición $index como una opción.
     *
     * @param list<string> $tokens argv completo.
     * @param list<Option> $admitidas Opciones globales conocidas.
     *
     * @throws ConsoleException Cuando la opción no existe, es una bandera con
     *                          valor, o le falta el valor.
     *
     * @return array{name: string, value: string|bool, consumed: int} El valor
     *                                                                es true en las banderas; consumed es cuántos tokens
     *                                                                adicionales se han tragado la opción.
     */
    /**
     * @param list<string> $tokens
     * @param list<Option> $admitidas
     *
     * @return array{name: string, value: string|bool, consumed: int}
     */
    private static function readOption(array $tokens, int $index, ?Command $command, array $admitidas): array
    {
        [$nombre, $valorInline] = self::splitOption($tokens[$index]);
        $opcion = self::findOption($nombre, $command, $admitidas);

        if ($opcion === null) {
            throw self::unknownOption($nombre, $command, $admitidas);
        }

        return $opcion->takesValue
            ? self::readValue($tokens, $index, $opcion, $valorInline)
            : self::readFlag($opcion, $valorInline);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{name: string, value: string|bool, consumed: int}
     */
    private static function readValue(array $tokens, int $index, Option $opcion, ?string $valorInline): array
    {
        if ($valorInline !== null) {
            return ['name' => $opcion->name, 'value' => $valorInline, 'consumed' => 0];
        }

        return self::readLooseValue($tokens, $index, $opcion);
    }

    /**
     * Una bandera solo puede aparecer desnuda; con valor es un error propio.
     *
     * @throws ConsoleException Si se le pasó un valor.
     *
     * @return array{name: string, value: string|bool, consumed: int}
     */
    private static function readFlag(Option $opcion, ?string $valorInline): array
    {
        if ($valorInline !== null) {
            throw new ConsoleException(sprintf(
                'La opción --%s no admite un valor.',
                $opcion->name,
            ));
        }

        return ['name' => $opcion->name, 'value' => true, 'consumed' => 0];
    }

    /**
     * El valor va en el token siguiente. No se acepta ahí otra opción: se
     * denuncia con el nombre de esta en vez de tragarse el comando que
     * sigue, que es lo que hace un parser ingenuo y produce errores que no
     * cuadran.
     *
     * @param list<string> $tokens
     *
     * @throws ConsoleException Si el valor falta o parece otra opción.
     *
     * @return array{name: string, value: string|bool, consumed: int}
     */
    private static function readLooseValue(array $tokens, int $index, Option $opcion): array
    {
        $siguiente = $tokens[$index + 1] ?? null;

        if ($siguiente === null || self::looksLikeOption($siguiente)) {
            throw self::missingValue($opcion);
        }

        return ['name' => $opcion->name, 'value' => $siguiente, 'consumed' => 1];
    }

    /**
     * @throws ConsoleException
     */
    private static function missingValue(Option $opcion): ConsoleException
    {
        return new ConsoleException(
            sprintf('La opción --%s necesita un valor.', $opcion->name),
            $opcion->example === null
                ? 'Consulta la ayuda del comando para ver los valores admitidos.'
                : sprintf('Ejemplo: --%s=%s', $opcion->name, $opcion->example),
        );
    }

    /**
     * Si la opción está activa, para las banderas.
     */
    public function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) !== false;
    }

    /**
     * El valor de una opción, o null si no se indicó.
     */
    public function value(string $name): ?string
    {
        $valor = $this->options[$name] ?? null;

        return is_string($valor) ? $valor : null;
    }

    /**
     * El nombre canónico del comando, o cadena vacía si no se indicó ninguno.
     */
    public function commandName(): string
    {
        return $this->command === null ? '' : $this->command->name;
    }

    /**
     * Los argumentos posicionales, emparejados con los nombres que el
     * comando declara, y comprobado que encajan con ellos.
     *
     * El emparejamiento es por posición y no por parecido de texto: el
     * valor escrito por quien usa la CLI es "laravel" y el nombre declarado
     * es "framework", así que buscar un parecido entre ambos no encuentra
     * nada y solo serviría para que un valor legítimo pareciera un error.
     *
     *
     * @throws ConsoleException Si sobran argumentos o falta uno obligatorio.
     *
     * @return array<string, string> nombre declarado => valor escrito
     */
    public function argumentsByName(): array
    {
        $declarados = $this->command === null ? [] : $this->command->arguments;
        $valores = $this->mapArgumentValues($declarados);

        $this->rejectExtraArgument($declarados);
        $this->rejectMissingArgument($declarados, $valores);

        return $valores;
    }

    /**
     * Empareja los argumentos escritos con los declarados por el comando,
     * por posición. Un hueco se salta: un argumento opcional no escrito no
     * anula los que le siguen.
     *
     * @param array<int, Argument> $declarados
     *
     * @return array<string, string>
     */
    private function mapArgumentValues(array $declarados): array
    {
        $valores = [];

        foreach ($declarados as $posicion => $declarado) {
            $valor = $this->arguments[$posicion] ?? null;

            if ($valor !== null) {
                $valores[$declarado->name] = $valor;
            }
        }

        return $valores;
    }

    /**
     * @param array<int, Argument> $declarados
     *
     * @throws ConsoleException Si se escribió más argumentos de los declarados.
     */
    private function rejectExtraArgument(array $declarados): void
    {
        if (count($this->arguments) <= count($declarados)) {
            return;
        }

        throw new ConsoleException(
            sprintf('Argumento inesperado: "%s".', $this->arguments[count($declarados)]),
            $this->extraArgumentHint($declarados),
        );
    }

    /**
     * Pista del rechazo: sin argumentos declarados lo útil son las opciones
     * disponibles; con ellos, la lista de los que caben.
     *
     * @param array<int, Argument> $declarados
     */
    private function extraArgumentHint(array $declarados): string
    {
        if ($declarados === []) {
            return $this->hintSinArgumentos();
        }

        return sprintf(
            'Admite como mucho %d argumento(s): %s.',
            count($declarados),
            implode(' ', array_map(
                static fn(Argument $argumento): string => $argumento->synopsis(),
                $declarados,
            )),
        );
    }

    /**
     * Sin argumentos declarados, lo útil que se puede listar son las opciones
     * que el comando sí admite.
     */
    private function hintSinArgumentos(): string
    {
        return sprintf(
            'Este comando no admite argumentos; sus opciones son: %s.',
            implode(', ', array_map(
                static fn(Option $opcion): string => $opcion->synopsis(),
                $this->command?->allOptions() ?? [],
            )),
        );
    }

    /**
     * @param array<int, Argument> $declarados
     * @param array<string, string> $valores
     *
     * @throws ConsoleException Si falta un argumento obligatorio.
     */
    private function rejectMissingArgument(array $declarados, array $valores): void
    {
        foreach ($declarados as $declarado) {
            if ($declarado->required && !isset($valores[$declarado->name])) {
                throw new ConsoleException(
                    sprintf('Falta el argumento <%s>.', $declarado->name),
                    $declarado->description === '' ? null : $declarado->description,
                );
            }
        }
    }

    /**
     * Un token es opción si empieza por guion y no es un guion suelto.
     */
    private static function looksLikeOption(string $token): bool
    {
        return strlen($token) > 1 && $token[0] === '-';
    }

    /**
     * Separa `--nombre=valor` en sus dos partes; `null` si no lleva valor.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function splitOption(string $token): array
    {
        $doble = str_starts_with($token, '--');
        $cuerpo = $doble ? substr($token, 2) : substr($token, 1);

        $pos = strpos($cuerpo, '=');

        return $pos === false
            ? [$cuerpo, null]
            : [substr($cuerpo, 0, $pos), substr($cuerpo, $pos + 1)];
    }

    private static function resolveCommand(string $token): Command
    {
        $command = Registry::find($token);

        if ($command !== null) {
            return $command;
        }

        throw new ConsoleException(
            sprintf('Comando desconocido: "%s".', $token),
            self::commandHint($token),
        );
    }

    /**
     * La sugerencia busca entre alias porque es donde más se teclea mal
     * (`ls` por `list`); la lista de fondo, en cambio, enseña solo los nombres
     * canónicos: enseñar los alias duplica la lista y sugiere escribir formas
     * alternativas donde la principal basta.
     */
    private static function commandHint(string $token): string
    {
        $sugerencia = self::suggest($token, self::commandTokens());

        if ($sugerencia !== null) {
            return sprintf('¿Querías decir "%s"?', $sugerencia);
        }

        return sprintf('Comandos disponibles: %s.', implode(', ', self::canonicalCommandTokens()));
    }

    /**
     * Busca la opción en las del comando y, si no hay comando aún, en las
     * globales.
     *
     * Antes del comando solo se admiten banderas globales, y no es una
     * simplificación: las opciones con valor son todas específicas de un
     * comando, así que aceptarlas ahí solo serviría para consumir por error
     * el nombre del comando como si fuera su valor.
     *
     * @param list<Option> $globales
     */
    private static function findOption(string $name, ?Command $command, array $globales): ?Option
    {
        $candidatas = $command === null ? $globales : [...$command->options, ...$globales];

        foreach ($candidatas as $opcion) {
            if ($opcion->name === $name || ($opcion->short !== null && $opcion->short === $name)) {
                return $opcion;
            }
        }

        return null;
    }

    /**
     * @param list<Option> $admitidas
     */
    private static function unknownOption(string $nombre, ?Command $command, array $admitidas): ConsoleException
    {
        $candidatas = $command === null ? $admitidas : [...$command->options, ...$admitidas];
        $nombres = self::listNames($candidatas);
        $sugerencia = self::suggest($nombre, $nombres);

        $pista = $sugerencia === null
            ? sprintf('Opciones válidas: %s.', implode(', ', $nombres))
            : sprintf('¿Querías decir "--%s"?', $sugerencia);

        return new ConsoleException(
            sprintf('Opción desconocida: "--%s".', $nombre),
            $pista,
        );
    }

    /**
     * @param list<Option> $admitidas
     *
     * @return list<string>
     */
    private static function listNames(array $admitidas): array
    {
        return array_values(array_map(static fn(Option $opcion): string => $opcion->name, $admitidas));
    }

    /**
     * @return list<string>
     */
    private static function commandTokens(): array
    {
        $tokens = [];

        foreach (Registry::commands() as $command) {
            foreach ($command->spellings() as $spelling) {
                $tokens[] = $spelling;
            }
        }

        return $tokens;
    }

    /**
     * Solo los nombres principales, sin alias.
     *
     * @return list<string>
     */
    private static function canonicalCommandTokens(): array
    {
        return array_map(
            static fn(Command $command): string => $command->name,
            Registry::commands(),
        );
    }

    /**
     * La coincidencia más cercana por distancia de edición, o null si ninguna
     * está lo bastante cerca.
     *
     * @param list<string> $candidatas
     */
    private static function suggest(string $needle, array $candidatas): ?string
    {
        $mejor = null;
        $mejorDistancia = self::SUGGESTION_DISTANCE + 1;

        foreach ($candidatas as $candidata) {
            $distancia = levenshtein($needle, $candidata);
            if ($distancia < $mejorDistancia) {
                $mejorDistancia = $distancia;
                $mejor = $candidata;
            }
        }

        return $mejor;
    }
}
