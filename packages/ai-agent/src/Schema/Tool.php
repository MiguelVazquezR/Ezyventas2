<?php

namespace Ezyventas\AiAgent\Schema;

use Closure;

class Tool
{
    /** @var ToolParameter[] */
    public array $parameters = [];

    private function __construct(
        public string $name,
        public string $description,
        public ?Closure $handler = null,
    ) {}

    public static function as(string $name): self
    {
        return new self($name, '');
    }

    public function for(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function withStringParameter(string $name, string $description): self
    {
        $this->parameters[] = new ToolParameter($name, 'string', $description);

        return $this;
    }

    public function withNumberParameter(string $name, string $description): self
    {
        $this->parameters[] = new ToolParameter($name, 'number', $description);

        return $this;
    }

    public function using(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Execute the tool handler with the given arguments.
     */
    public function execute(array $args): string
    {
        if (! $this->handler) {
            return json_encode(['error' => 'Tool has no handler']);
        }

        return ($this->handler)(...$args);
    }

    /**
     * Convert to the JSON schema format expected by Anthropic/OpenAI.
     */
    public function toProviderSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $param) {
            $properties[$param->name] = [
                'type'        => $param->type,
                'description' => $param->description,
            ];
            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return [
            'name'        => $this->name,
            'description' => $this->description,
            'input_schema' => [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => $required,
            ],
        ];
    }
}
