<?php

namespace App\Games;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * 可用的遊戲。資料來自 packages/games/manifest.json，由各遊戲套件的 meta.ts 自動產生
 * （npm run games:manifest），伺服器端用它判斷題目形狀、是否計分，並驗證遊戲設定。
 *
 * @phpstan-type Game array{id: string, version: string, title: array{'zh-TW': string}, requires: array{shape: string, minRounds: int, scored: bool}, optionsSchema: array<string, mixed>, defaultOptions: array<string, mixed>}
 */
class GameRegistry
{
    /** @var array<string, Game>|null */
    private ?array $games = null;

    public function __construct(private ?string $manifestPath = null) {}

    /**
     * @return array<string, Game>
     */
    public function all(): array
    {
        if ($this->games === null) {
            $path = $this->manifestPath ?? base_path('packages/games/manifest.json');
            $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            $this->games = [];
            foreach ($manifest['games'] as $game) {
                $this->games[$game['id']] = $game;
            }
        }

        return $this->games;
    }

    /**
     * @return Game|null
     */
    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @return Game
     */
    public function get(string $id): array
    {
        return $this->find($id) ?? throw new RuntimeException("找不到遊戲：{$id}");
    }

    /**
     * 老師端顯示的遊戲名稱；找不到時（例如遊戲已移除）顯示 ID。
     */
    public function title(string $id): string
    {
        return $this->find($id)['title']['zh-TW'] ?? $id;
    }

    public function shape(string $id): string
    {
        return $this->get($id)['requires']['shape'];
    }

    /**
     * 以遊戲的預設值補齊設定。
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function withDefaults(string $id, array $options): array
    {
        return array_replace($this->get($id)['defaultOptions'], $options);
    }

    /**
     * 依遊戲的 optionsSchema 驗證設定。
     *
     * @param  array<string, mixed>  $options
     * @return list<string> 空陣列表示通過
     */
    public function optionErrors(string $id, array $options): array
    {
        $schema = json_decode(json_encode($this->get($id)['optionsSchema'], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $data = json_decode(json_encode((object) $options, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

        $error = (new Validator)->validate($data, $schema)->error();
        if ($error === null) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter)->format($error) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = "{$path}: {$message}";
            }
        }

        return $errors;
    }
}
