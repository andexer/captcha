<?php

declare(strict_types=1);

namespace Captcha\Generator;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Config\Operation;
use Captcha\Contract\ExpressionProviderInterface;
use Captcha\Contract\GeneratorInterface;
use Captcha\Exception\InvalidConfigException;

/**
 * Genera códigos compuestos únicamente por dígitos usando un CSPRNG.
 *
 * Sin operaciones habilitadas se comporta como un generador de dígitos
 * plano. Con un conjunto habilitado, generate() devuelve el resultado
 * numérico de una expresión ASCII aleatoria ("12*3") mientras expression()
 * expone el texto dibujado en la imagen; solo se eligen las operaciones
 * habilitadas, la resta nunca es negativa y la división es siempre exacta.
 * La dificultad gradúa el tamaño de los operandos (escalado dentro de la
 * banda que $length permite) y el resultado nunca supera la $length del
 * código, igualando el maxlength de la entrada.
 * Un rango opcional [min, max] ("between") confina cada respuesta
 * aritmética a ese intervalo inclusivo (además del techo de $length).
 */
final class NumericGenerator implements GeneratorInterface, ExpressionProviderInterface
{
    private const MAX_ATTEMPTS = 200;

    /** @var list<Operation> */
    private readonly array $operations;

    /** @var array{int, int}|null */
    private readonly ?array $between;

    private string $expression = '';

    /**
     * @param list<Operation> $operations Operaciones habilitadas; vacío = dígitos.
     * @param array{int, int}|null $between Rango inclusivo de resultados
     *                                      aritméticos [min, max] con
     *                                      0 <= min <= max; null desactiva el
     *                                      acote adicional.
     *
     * @throws InvalidConfigException Cuando el rango está mal formado.
     */
    public function __construct(
        array $operations = [],
        private readonly Difficulty $difficulty = Difficulty::Medium,
        ?array $between = null,
    ) {
        $this->operations = self::dedupe($operations);
        $this->between = self::normalizeBetween($between);
    }

    /**
     * @throws InvalidConfigException
     */
    public function generate(int $length): string
    {
        self::exigeLongitudValida($length);

        if ($this->operations === []) {
            $this->expression = '';

            return $this->randomDigits($length);
        }

        return $this->generateArithmetic($length);
    }

    private static function exigeLongitudValida(int $length): void
    {
        if ($length < Config::MIN_LENGTH || $length > Config::MAX_LENGTH) {
            throw new InvalidConfigException(sprintf(
                'La longitud debe estar entre %d y %d; se recibió %d.',
                Config::MIN_LENGTH,
                Config::MAX_LENGTH,
                $length,
            ));
        }
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * Si esta instancia está configurada en modo aritmético.
     */
    public function hasOperation(): bool
    {
        return $this->operations !== [];
    }

    private function randomDigits(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }

        return $code;
    }

    /**
     * Rango admisible para la respuesta: el tope del código cuando no se
     * configuró "between", o el rango pedido recortado a ese tope.
     *
     * @throws InvalidConfigException Si el rango pedido no cabe en el código.
     *
     * @return array{0: int, 1: int}
     */
    private function resultBounds(int $length): array
    {
        $tope = (10 ** $length) - 1;

        if ($this->between === null) {
            return [0, $tope];
        }

        [$minResult, $maxResult] = $this->between;

        return $this->recortaAlTope($minResult, min($maxResult, $tope), $length, $tope);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function recortaAlTope(int $min, int $max, int $length, int $tope): array
    {
        if ($min > $max) {
            self::rangoImposible($min, $max, $length, $tope);
        }

        return [$min, $max];
    }

    /**
     * Un rango de resultados más ancho que el código no tiene arreglo: el
     * destino imposible se reporta aquí y no con un código que no existe.
     */
    private static function rangoImposible(int $min, int $max, int $length, int $tope): never
    {
        throw new InvalidConfigException(sprintf(
            'El rango de "between" [%d, %d] no cabe en un código de %d dígitos (máx. %d).',
            $min,
            $max,
            $length,
            $tope,
        ));
    }

    /**
     * Construye una expresión aleatoria a partir de una operación habilitada.
     *
     * El resultado queda siempre acotado por la $length del código (necesita
     * como mucho $length dígitos) Y por el rango opcional "between": ambos
     * techos se aplican, gana el límite inferior más alto y el superior más
     * bajo. Los operandos se dimensionan con techoOperandos($length), que a su
     * vez respeta "between"; sin ese confinamiento una resta con rango [0, 20]
     * podía pintar 3962-3962: el resultado era válido y la imagen ilegible.
     *
     * @throws InvalidConfigException Cuando no se encuentra una expresión válida.
     */
    private function generateArithmetic(int $length): string
    {
        $operations = $this->operations;
        $operation = $operations[random_int(0, count($operations) - 1)];
        $max = $this->techoOperandos($length);
        $bounds = $this->resultBounds($length);
        $expresion = $this->intentaOperation($operation, $max, $bounds);

        if ($expresion === null) {
            $this->expression = '';
            throw new InvalidConfigException(self::sinExpresionValida());
        }

        return $expresion;
    }

    /**
     * @param array{0: int, 1: int} $bounds
     */
    private function intentaOperation(Operation $operation, int $max, array $bounds): ?string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $operands = $this->operands($operation, $max, $bounds[0], $bounds[1]);

            if ($operands !== null) {
                return $this->acepta($operation, $operands);
            }
        }

        return null;
    }

    private static function sinExpresionValida(): string
    {
        return 'No se pudo generar una operación aritmética con estos parámetros.';
    }

    /**
     * Un sorteo válido cierra el método: la expresión guardada es la que
     * verá quien pida el reto.
     */
    private function acepta(Operation $operation, Operands $operands): string
    {
        $this->expression = $operands->expressionFor($operation);

        return (string) $operands->answer;
    }

    /**
     * Reparte el sorteo entre las cuatro operaciones.
     *
     * Devolver null significa «este sorteo no valía» y el llamador reintenta.
     * Cada operación tiene su propio método porque cada una razona distinto
     * sobre los mismos tres techos: una suma o una multiplicación tratan el
     * mínimo como suelo de la respuesta, una resta lo trata como techo del
     * sustraendo y una división exacta sortea primero el cociente.
     */
    private function operands(Operation $operation, int $max, int $minResult, int $maxResult): ?Operands
    {
        if ($maxResult < $minResult) {
            return null;
        }

        return match ($operation) {
            Operation::Add => $this->suma($max, $minResult, $maxResult),
            Operation::Subtract => $this->resta($max, $minResult, $maxResult),
            Operation::Multiply => $this->producto($max, $minResult, $maxResult),
            Operation::Divide => $this->cociente($max, $minResult, $maxResult),
        };
    }

    /**
     * a + b dentro del rango pedido.
     *
     * El primer operando se acota por el resultado que aún queda por gastar, y
     * el segundo por lo que resta del rango: así la respuesta nunca se pasa y
     * no hace falta reintentar a ciegas.
     */
    private function suma(int $max, int $minResult, int $maxResult): ?Operands
    {
        $a = self::sorteaEn(1, min($max, $maxResult - 1));

        if ($a === null) {
            return null;
        }

        return $this->siCabe(min($max, $maxResult - $a), $a, $minResult, static fn(int $x, int $b): int => $x + $b);
    }

    /**
     * Un entero de [min, $max], o null si ese rango no admite ningún valor.
     *
     * Todos los sorteos de operandos pasan por aquí, para que "el rango está
     * vacío" se exprese siempre igual: un random_int() con rango inválido lanza
     * ValueError y eso sería un bug de configuración disfrazado de sorteo.
     */
    private static function sorteaEn(int $min, int $max): ?int
    {
        return $max < $min ? null : random_int($min, $max);
    }

    /**
     * El segundo operando se sortea solo si el rango que le toca lo permite; un
     * resultado por debajo del mínimo no vale como sorteo y el llamador
     * reintenta con otro.
     *
     * @param callable(int, int): int $calculo
     */
    private function siCabe(int $bMax, int $a, int $minResult, callable $calculo): ?Operands
    {
        if ($bMax < 1) {
            return null;
        }

        $b = random_int(1, $bMax);
        $answer = $calculo($a, $b);

        return $answer >= $minResult ? new Operands($a, $b, $answer) : null;
    }

    /**
     * a - b en [min, max] con a <= max, de donde b <= max - min.
     *
     * El sustraendo se sortea primero porque es el que acota al minuendo, que
     * se sortea después en [b + min, min(max, max + b)].
     */
    private function resta(int $max, int $minResult, int $maxResult): ?Operands
    {
        $b = self::sorteaEn(1, min($max, $max - $minResult));

        if ($b === null) {
            return null;
        }

        $a = self::sorteaEn($b + $minResult, min($max, $maxResult + $b));

        return $a === null ? null : new Operands($a, $b, $a - $b);
    }

    /**
     * a * b dentro del rango pedido.
     *
     * El primer operando se sortea antes que el segundo porque el producto es
     * creciente en ambos: acotando b por intdiv(max, a) tras sortear a, la
     * respuesta cae dentro del techo sin reintentos.
     */
    private function producto(int $max, int $minResult, int $maxResult): ?Operands
    {
        $a = self::sorteaEn(1, min($max, $maxResult));

        if ($a === null) {
            return null;
        }

        return $this->siCabe(min($max, intdiv($maxResult, $a)), $a, $minResult, static fn(int $x, int $b): int => $x * $b);
    }

    /**
     * División exacta: la imagen muestra (divisor * resultado) / divisor.
     *
     * Se sortea primero el cociente y luego el divisor, y el numerando se
     * compone como producto, de modo que la operación de la imagen sea
     * siempre entera y el usuario no tenga que calcular resto.
     */
    private function cociente(int $max, int $minResult, int $maxResult): ?Operands
    {
        $result = self::sorteaEn(max(1, $minResult), min($max, $maxResult));

        if ($result === null) {
            return null;
        }

        $divisor = self::sorteaEn(2, min($max, intdiv($max, $result)));

        return $divisor === null ? null : new Operands($divisor * $result, $divisor, $result);
    }

    /**
     * Techo de los operandos para un código de $length dígitos.
     *
     * Sin "between" se dimensiona por operandMax($length): la longitud empuja
     * el techo y la dificultad gradúa una banda dentro de él. Con "between"
     * el sorteo tampoco puede pasarse del rango confinando el resultado, así
     * que el techo se repliega a su entorno: max + un 25 % del ancho, con el
     * default de la dificultad como límite. Así una resta de [0, 20] muestra
     * operandos de hasta 25, no de hasta 9999.
     */
    private function techoOperandos(int $length): int
    {
        $techo = $this->operandMax($length);

        if ($this->between === null) {
            return $techo;
        }

        [$min, $max] = $this->between;
        $margen = intdiv($max - $min, 4);

        return max($max, min($techo, $max + $margen));
    }

    /**
     * Techo de los operandos para un código de $length dígitos.
     *
     * El rango de operandos se dimensiona por $length, no solo por la
     * dificultad: un código de 6 dígitos acotaba los operandos a 999 (High) o
     * 99 (Medium), de modo que todo el espacio de respuestas era de ~197
     * valores — un barrido a ciegas lo rompía en un par de decenas de
     * renders, y los seis dígitos que pedía el llamador eran mentira.
     * $length ahora empuja el techo y la dificultad gradúa una banda dentro
     * de él, de modo que la entropía prometida es real mientras los niveles
     * siguen significando algo:
     *
     *  - Low    — operandos en torno a sqrt(techo): pequeños y legibles, de
     *             modo que una suma cae bien por debajo del rango completo de
     *             $length (un código de 6 dígitos se queda en torno a 1998
     *             aquí). El más barato de resolver y de leer: ese es el punto
     *             del nivel.
     *  - Medium — una décima parte del techo, el default.
     *  - High   — la mitad del techo.
     *
     * @param int $length Longitud del código en dígitos (3..10).
     */
    private function operandMax(int $length): int
    {
        $ceiling = (10 ** $length) - 1;

        return match ($this->difficulty) {
            Difficulty::Low => max(9, (int) sqrt($ceiling)),
            Difficulty::High => intdiv($ceiling, 2),
            default => max(9, intdiv($ceiling, 10)),
        };
    }

    /**
     * Valida y normaliza el rango opcional de resultados aritméticos.
     *
     * @param array{int, int}|null $between
     *
     * @throws InvalidConfigException Cuando el rango está mal formado.
     *
     * @return array{int, int}|null
     */
    private static function normalizeBetween(?array $between): ?array
    {
        if ($between === null) {
            return null;
        }

        self::exigeBienFormado($between);

        [$min, $max] = $between;
        if ($min < 0 || $min > $max) {
            self::rangoIncoherente($min, $max);
        }

        return [$min, $max];
    }

    /**
     * @param array<array-key, mixed> $between
     */
    private static function exigeBienFormado(array $between): void
    {
        if (array_is_list($between) && count($between) === 2 && is_int($between[0]) && is_int($between[1])) {
            return;
        }

        throw new InvalidConfigException(self::betweenMalFormado());
    }

    private static function betweenMalFormado(): string
    {
        return "'between' debe ser un array de dos enteros, p. ej. [2, 20].";
    }

    private static function rangoIncoherente(int $min, int $max): never
    {
        throw new InvalidConfigException(sprintf(
            "'between' requiere 0 <= min <= max; se recibió [%d, %d].",
            $min,
            $max,
        ));
    }

    /**
     * @param list<Operation> $operations
     *
     * @return list<Operation>
     */
    private static function dedupe(array $operations): array
    {
        $seen = [];

        foreach ($operations as $operation) {
            if (!$operation instanceof Operation || isset($seen[$operation->value])) {
                continue;
            }

            $seen[$operation->value] = $operation;
        }

        return array_values($seen);
    }
}
