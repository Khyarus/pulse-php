# PulsePHP

**Observabilidade e telemetria para PHP 8.1+ com armazenamento local em SQLite e integração opcional com Laravel.**

[![Build](https://github.com/jgcansi/pulse-php/actions/workflows/tests.yml/badge.svg)](https://github.com/jgcansi/pulse-php/actions/workflows/tests.yml)
[![Version](https://img.shields.io/badge/version-1.0.0--alpha-rose.svg)](https://github.com/jgcansi/pulse-php/releases/tag/v1.0.0-alpha)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)

PulsePHP registra eventos, métricas, tempos de execução, requisições, exceções e padrões de consultas SQL em um banco SQLite local. O núcleo não depende do Laravel nem de serviços externos; a integração com Laravel é opcional e descoberta pelo Composer.

## Recursos

- Buffer em memória com gravação em lote no encerramento do processo.
- Normalização e catalogação de SQL para agrupar consultas com valores diferentes.
- Timers para medir duração e variação de memória de operações.
- Captura de exceções e, em requisições web, dados de requisição.
- Dashboard HTML com atividade, consultas lentas, spans e exceções recentes.
- Acesso ao Dashboard negado por padrão; políticas podem combinar Basic Auth, IPs permitidos e callback ou Gate do Laravel.
- Integração opcional com Laravel 9+ sem dependência `illuminate/*` no uso standalone.

## Requisitos e instalação

- PHP 8.1 ou superior.
- Extensões `pdo` e `pdo_sqlite`.

Instale pelo Composer:

```bash
composer require jgcansi/pulse-php
```

## PHP standalone

Inicialize o Pulse uma vez no ponto de entrada da aplicação. Os helpers `pulse()`, `pulse_metric()`, `pulse_start()` e `pulse_end()` são carregados pelo Composer:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PulsePHP\Pulse;

Pulse::init(__DIR__ . '/storage/pulse.sqlite');

pulse('pedido.criado');
pulse_metric('valor_venda', 250.00, ['categoria' => 'eletronicos']);

pulse_start('relatorio.gerar');
// Gere o relatório.
pulse_end('relatorio.gerar');
```

### Dashboard standalone

O repositório inclui `public/dashboard.php`. Para executá-lo localmente, configure credenciais fortes e use o servidor embutido apenas em loopback:

```bash
export PULSE_DASHBOARD_USER=pulse-admin
export PULSE_DASHBOARD_PASSWORD='use-um-segredo-forte'
php -S 127.0.0.1:8000 -t public
```

Acesse `http://127.0.0.1:8000/dashboard.php`. O exemplo restringe o acesso a `127.0.0.1` e `::1`; quando as variáveis Basic Auth estão configuradas, as credenciais também são exigidas. As políticas são cumulativas. Não exponha o endpoint publicamente sem configurar autenticação, restrições de rede e HTTPS.

Aplicações que instanciam o Dashboard diretamente devem configurar pelo menos uma política. Por exemplo:

```php
use PulsePHP\Dashboard\Dashboard;

$dashboard = new Dashboard($databasePath);
$dashboard->authorize(static fn (): bool => $currentUser->isAdmin());
$dashboard->render();
```

## Laravel

O service provider é descoberto automaticamente pelo Composer. Publique a configuração:

```bash
php artisan vendor:publish --tag=pulse-config
```

Por padrão, o Dashboard fica na rota `/pulse` e o banco em `storage/pulse.sqlite`. Para alterar o caminho do banco, edite `database_path` em `config/pulse.php`.

O acesso permanece negado até a aplicação definir o Gate `viewPulse`, por exemplo em um provider de autorização:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewPulse', static fn (User $user): bool => $user->is_admin);
```

Opcionalmente, configure Basic Auth e uma lista de IPs permitidos no `.env`:

```dotenv
PULSE_DASHBOARD_USER=pulse-admin
PULSE_DASHBOARD_PASSWORD=use-um-segredo-forte
PULSE_DASHBOARD_IPS=127.0.0.1,::1
```

Quando configuradas, as credenciais e a whitelist são verificadas além do Gate. O arquivo publicado também permite ajustar `enabled`, rota, middleware e coletores.

## Testes

Instale as dependências de desenvolvimento e execute PHPUnit:

```bash
composer install
vendor/bin/phpunit
```

O GitHub Actions executa a suíte em PHP 8.1, 8.2 e 8.3.

## Licença

PulsePHP é distribuído sob a [Licença MIT](LICENSE). Criado e mantido por **João Gabriel Cansi Silveira**.
