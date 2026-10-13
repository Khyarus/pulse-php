<p align="center"><img src="./docs/banner.svg" alt="Banner do PulsePHP" width="100%"></p>

<h1 align="center">PulsePHP</h1>

<p align="center">
  <b>Observabilidade, monitoramento e telemetria para PHP 8.1+ e Laravel — 100% local, em SQLite.</b>
  <br/>
  <sub>Sem banco externo, sem agente, sem SaaS. Um único arquivo, um dashboard completo.</sub>
</p>

<p align="center">
  <a href="#-demonstração-em-ação"><b>Demo</b></a> ·
  <a href="#-instalação"><b>Instalação</b></a> ·
  <a href="#-uso"><b>Uso</b></a> ·
  <a href="#-laravel"><b>Laravel</b></a> ·
  <a href="#-roadmap"><b>Roadmap</b></a> ·
  <a href="#-contribuição"><b>Contribuição</b></a>
</p>

<!-- BADGES -->
<p align="center">
  <a href="https://github.com/jgcansi/pulse-php/releases"><img src="https://img.shields.io/badge/versão-1.0.0-3aa0ff?style=for-the-badge" alt="Versão"></a>
  <a href="https://github.com/jgcansi/pulse-php/actions/workflows/tests.yml"><img src="https://img.shields.io/badge/testes-passing-42c98a?style=for-the-badge" alt="Testes"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/licença-MIT-42c98a?style=for-the-badge" alt="Licença"></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Laravel-9%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/SQLite-003B57?style=flat-square&logo=sqlite&logoColor=white" alt="SQLite">
  <img src="https://img.shields.io/badge/Composer-885630?style=flat-square&logo=composer&logoColor=white" alt="Composer">
  <img src="https://img.shields.io/badge/PHPUnit-3aa0ff?style=flat-square&logo=phpunit&logoColor=white" alt="PHPUnit">
</p>

<p align="center">
  <a href="#-demonstração-em-ação"><img src="https://img.shields.io/badge/▶_Ver_Demo-42c98a?style=for-the-badge" alt="Ver demo"></a>
    <a href="#-instalação"><img src="https://img.shields.io/badge/⚙_Instalação-3aa0ff?style=for-the-badge" alt="Instalação"></a>
  <a href="#-uso"><img src="https://img.shields.io/badge/📚_Documentação-555?style=for-the-badge" alt="Documentação"></a>
  <a href="https://github.com/jgcansi/pulse-php/discussions"><img src="https://img.shields.io/badge/💬_Discussões-5865F2?style=for-the-badge" alt="Discussões"></a>
</p>

<p align="center">
  <a href="#-demonstração-em-ação">
    <img src="./docs/dashboard-graficos.png" alt="Dashboard do PulsePHP rodando com gráficos ao vivo" width="100%">
  </a>
  <br/>
  <sub>Dashboard do PulsePHP rodando localmente — <a href="#-demonstração-em-ação">veja mais prints ↓</a></sub>
</p>

---

## 🎬 Demonstração em Ação

> Prints reais do dashboard rodando: navegação, filtros por período/serviço/rota, gráficos, widgets e detecção de erros em tempo real.

### 📊 Visão geral e gráficos

<p align="center">
  <img src="./docs/dashboard-graficos.png" alt="Dashboard do PulsePHP com gráficos de throughput e latência" width="90%">
  <br/>
  <sub>Gráficos ao vivo de requisições, latência e throughput por serviço e rota.</sub>
</p>

### 🧩 Widgets e atividade

<p align="center">
  <img src="./docs/widgets.png" alt="Widgets do dashboard: slow queries, slow spans e chamadas externas" width="90%">
  <br/>
  <sub>Widgets: slow query patterns, slow spans e outbound API calls.</sub>
</p>

<p align="center">
  <img src="./docs/atividade-lista.png" alt="Lista de atividade com requisições recentes" width="90%">
  <br/>
  <sub>Lista de atividade — requisições recentes com método, status e duração.</sub>
</p>

### 🩺 Saúde por rota

<p align="center">
  <img src="./docs/saude-rota.png" alt="Cartão de saúde de uma rota saudável" width="48%">
  &nbsp;
  <img src="./docs/saude-rota-warning.png" alt="Cartão de saúde de uma rota com aviso" width="48%">
  <br/>
  <sub>Cartões de saúde por rota — estado saudável (esquerda) e estado de atenção (direita).</sub>
</p>

### 🚨 Erros e exceções

<p align="center">
  <img src="./docs/erros.png" alt="Painel de erros e exceções do PulsePHP" width="90%">
  <br/>
  <sub>Painel de erros — exceções recentes com classe, rota e serviço.</sub>
</p>

<p align="center">
  <img src="./docs/terminal.svg" alt="Instalação e execução do PulsePHP no terminal" width="90%">
  <br/>
  <sub>Terminal — instalação via Composer, execução e tráfego de exemplo.</sub>
</p>

---

## 🔭 Visão Geral

**PulsePHP** é uma biblioteca de observabilidade local para **PHP 8.1+** e **Laravel 9+**. Ela captura requisições, exceções, queries SQL e chamadas externas, e mostra tudo em um **dashboard ao vivo** — usando apenas um arquivo **SQLite**, sem nenhuma infraestrutura externa (sem Redis, sem banco de métricas, sem serviço na nuvem).

- 🎯 **Local por padrão** — seus dados ficam no seu servidor, em um SQLite.
- ⚡ **Leve** — buffer em memória com gravação em lote, sem overhead por request.
- 🧩 **Integrável** — funciona standalone em PHP puro ou plugado no Laravel 9+.
- 🔐 **Seguro** — dashboard negado por padrão (Basic Auth + IPs + Gate).

<p align="center">
  <img src="./docs/dashboard-graficos.png" alt="Visão geral do PulsePHP no dashboard" width="90%">
  <br/>
  <sub>Tudo em um único SQLite: requisições, latência, queries, spans e chamadas externas.</sub>
</p>

---

## ✨ Recursos Principais

<p align="center">
  <img src="./docs/features.svg" alt="Recursos do PulsePHP" width="90%">
</p>

- [x] Buffer em memória com gravação em lote, seguro para filas, Octane e Horizon.
- [x] Normalização e catalogação de SQL para agrupar consultas equivalentes.
- [x] Timers para medir duração e variação de memória de operações.
- [x] Captura automática de requisições web, exceções e jobs de fila.
- [x] **Traces**: request, queries, spans, chamadas externas e exceções correlacionados, com página de detalhe.
- [x] **Detecção de N+1** e **exceções agrupadas** por classe e local.
- [x] Latência **p95/p99** no resumo e por rota.
- [x] Captura de chamadas do cliente `Http` do Laravel e do **Guzzle**; queries em PHP puro via **`TracedPDO`**.
- [x] **Retenção** configurável, **sampling** e lista de caminhos ignorados.
- [x] **Alertas** por webhook (JSON/Slack) e e-mail.
- [x] Dashboard ao vivo com filtros por período, serviço e rota — assets embutidos, sem CDN.
- [x] Segurança por padrão: dashboard negado até configurar uma política de acesso.
- [x] Integração opcional com Laravel 9+ via auto-discovery.
- [x] Dados sempre locais — nada sai da sua máquina (exceto os alertas que você configurar).

<table>
  <tr>
    <td width="50%" align="center">
      <img src="./docs/widgets.png" alt="Widgets de slow queries, slow spans e chamadas externas" width="100%">
      <br/>
      <sub><b>Widgets</b> — slow queries, spans e chamadas externas.</sub>
    </td>
    <td width="50%" align="center">
      <img src="./docs/saude-rota-warning.png" alt="Saúde por rota com estado de atenção" width="100%">
      <br/>
      <sub><b>Saúde por rota</b> — detecção de degradação.</sub>
    </td>
  </tr>
</table>

---

## 🏗️ Arquitetura & Tecnologias

| Camada | Tecnologia | Descrição |
| --- | --- | --- |
| **Core** | PHP 8.1+ | Núcleo standalone, sem dependência de framework. |
| **Integração** | Laravel 9+ | Service provider com auto-discovery (opcional). |
| **Dados** | SQLite (`pdo_sqlite`) | Armazenamento local em arquivo único. |
| **Dashboard** | HTML + Alpine.js + Chart.js | Filtros, gráficos, sparklines e auto-refresh; assets embutidos no pacote. |
| **Testes** | PHPUnit + Testbench | Suíte automatizada em PHP 8.1 a 8.4, incluindo integração com Laravel. |

<p align="center">
  <img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/SQLite-003B57?style=for-the-badge&logo=sqlite&logoColor=white" alt="SQLite">
  <img src="https://img.shields.io/badge/Composer-885630?style=for-the-badge&logo=composer&logoColor=white" alt="Composer">
  <img src="https://img.shields.io/badge/PHPUnit-3aa0ff?style=for-the-badge&logo=phpunit&logoColor=white" alt="PHPUnit">
</p>

---

## ⚙️ Instalação

### Pré-requisitos

```bash
php --version            # 8.1 ou superior
php -m | grep -i sqlite  # extensões pdo e pdo_sqlite
```

### Instale pelo Composer

```bash
composer require jgcansi/pulse-php
```

**Requisitos:** PHP 8.1+ com as extensões `pdo`, `pdo_sqlite` e `json` (esta última já vem habilitada por padrão no PHP 8+).

---



## 📖 Uso

### Uso rápido (standalone)

Inicialize o Pulse **uma vez** no ponto de entrada da aplicação. Os helpers `pulse()`, `pulse_metric()`, `pulse_start()` e `pulse_end()` são carregados automaticamente pelo Composer:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PulsePHP\Pulse;

Pulse::init(__DIR__ . '/storage/pulse.sqlite', options: [
    'sample_rate' => 1.0,              // fração de requisições gravadas
    'ignore_paths' => ['/health'],     // nunca gravados (* é curinga)
    'retention_days' => 7,             // limpeza automática de dados antigos
]);

pulse('pedido.criado');
pulse_metric('valor_venda', 250.00, ['categoria' => 'eletronicos']);

pulse_start('relatorio.gerar');
// Gere o relatório.
pulse_end('relatorio.gerar');
```

### Subir o Dashboard local

O repositório inclui `public/dashboard.php`. Configure credenciais fortes e rode o servidor embutido **apenas em loopback**:

```bash
# Linux / macOS
export PULSE_DASHBOARD_USER=pulse-admin
export PULSE_DASHBOARD_PASSWORD='use-um-segredo-forte'
php -S 127.0.0.1:8000 -t public

# Windows (PowerShell)
$env:PULSE_DASHBOARD_USER="pulse-admin"
$env:PULSE_DASHBOARD_PASSWORD="use-um-segredo-forte"
php -S 127.0.0.1:8000 -t public
```

Acesse **http://127.0.0.1:8000/dashboard.php**

O exemplo restringe o acesso a `127.0.0.1` e `::1`; quando as variáveis Basic Auth estão configuradas, as credenciais também são exigidas. As políticas são cumulativas. Não exponha o endpoint publicamente sem autenticação, restrições de rede e HTTPS.

### Gerar tráfego de exemplo

Em outra janela do terminal, simule requisições para ver o dashboard ganhar vida (a partir da raiz do repositório):

```bash
# Contínuo (Ctrl+C para parar)
php examples/traffic.php

# Execução controlada — útil para demos e GIFs
php examples/traffic.php --iterations=10
```

> O script grava cada request imediatamente em `storage/database.sqlite`.

Com o tráfego rodando, o dashboard ganha vida em segundos — incluindo a detecção de erros:

<p align="center">
  <img src="./docs/erros.png" alt="Painel de erros e exceções do PulsePHP" width="85%">
  <br/>
  <sub>Exceções recentes com classe, rota e serviço, capturadas automaticamente.</sub>
</p>

### Como instrumentar sua aplicação

Em PHP puro, requisições web e exceções são capturadas automaticamente pelos coletores do `Pulse::init()`. Cada requisição vira um **trace**: tudo o que ela gerar fica correlacionado e aparece na página de detalhe do dashboard.

**Queries SQL** — troque o `PDO` por `TracedPDO` (mesma API). Só o SQL normalizado e a duração são gravados; os valores nunca saem da aplicação:

```php
use PulsePHP\Database\TracedPDO;

$pdo = new TracedPDO('mysql:host=localhost;dbname=app', $user, $password);
```

Ou registre manualmente:

```php
Pulse::getInstance()->recordQuery('SELECT * FROM users WHERE id = 42', 1.25);
```

**Chamadas HTTP com Guzzle** — adicione o middleware ao `HandlerStack`:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use PulsePHP\Http\GuzzleMiddleware;

$stack = HandlerStack::create();
$stack->push(GuzzleMiddleware::create(), 'pulse');
$client = new Client(['handler' => $stack]);
```

Credenciais e query string são removidas da URL antes de gravar.

**Workers de longa duração** (loops de fila próprios, ReactPHP etc.) — marque cada unidade de trabalho e grave o buffer ao final:

```php
$pulse = Pulse::getInstance();
$pulse->beginTrace('job:enviar-email', 'JOB');
// ... trabalho ...
$pulse->writeBuffer();
```

### Retenção e alertas (CLI)

O pacote instala o binário `vendor/bin/pulse`. Agende-o no cron:

```bash
# Apaga telemetria com mais de 7 dias (diariamente)
vendor/bin/pulse prune --db=storage/pulse.sqlite --days=7

# Avalia alertas e notifica um webhook (a cada minuto)
vendor/bin/pulse check --db=storage/pulse.sqlite \
    --webhook=https://hooks.slack.com/services/... --format=slack \
    --error-rate=5 --exceptions=10 --p95=2000
```

Regras: taxa de 5xx, número de exceções e latência p95 numa janela (`--window`, padrão 5 min), com cooldown (`--cooldown`, padrão 30 min). Use `off` para desligar uma regra e `--dry-run` para testar sem notificar.

Aplicações que instanciam o Dashboard diretamente devem configurar pelo menos uma política:

```php
use PulsePHP\Dashboard\Dashboard;

$dashboard = new Dashboard($databasePath);
$dashboard->authorize(static fn (): bool => $currentUser->isAdmin());
$dashboard->render();
```



---

## 🚀 Laravel

O service provider é **descoberto automaticamente** pelo Composer. Nenhum passo extra de registro.

### 1. Instale

```bash
php artisan pulse:install
```

O comando publica `config/pulse.php` e mostra os próximos passos.

Por padrão, o Dashboard fica em **`/pulse`** e o banco em `storage/pulse.sqlite` (ajustável via `database_path` em `config/pulse.php`).

### 2. Autorize o acesso

O acesso permanece **negado até você definir o Gate `viewPulse`**:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewPulse', static fn (User $user): bool => $user->is_admin);
```

Opcionalmente, combine Basic Auth e whitelist de IPs no `.env`:

```dotenv
PULSE_DASHBOARD_USER=pulse-admin
PULSE_DASHBOARD_PASSWORD=use-um-segredo-forte
PULSE_DASHBOARD_IPS=127.0.0.1,::1
PULSE_SERVICE_NAME=api-pagamentos
```

Quando configuradas, as credenciais e a whitelist são verificadas além do Gate. O arquivo publicado também permite ajustar `enabled`, rota, middleware e coletores.

### 3. Agende a manutenção

```php
// routes/console.php (Laravel 11+; no 9/10 use o schedule() de app/Console/Kernel.php)
use Illuminate\Support\Facades\Schedule;

Schedule::command('pulse:prune')->daily();
Schedule::command('pulse:check')->everyMinute(); // com PULSE_ALERTS_ENABLED=true
```

Alertas usam `PULSE_ALERT_WEBHOOK` (JSON ou Slack) e/ou `PULSE_ALERT_MAIL_TO` (mailer do Laravel).

### 4. Pronto — a captura é automática

- **Requisições** dos grupos `web` e `api`, com nome da rota, sem query string.
- **Exceções** reportadas pelo Laravel (HTTP, jobs e console). O que está em `dontReport` (404, validação etc.) é ignorado.
- **Queries** via `DB::listen`, correlacionadas ao request ou job.
- **Jobs de fila** viram traces próprios (`job:App\Jobs\...`) com span de duração.
- O buffer é gravado ao fim de cada request e de cada job, então **Octane, Horizon e `queue:work`** não acumulam memória.

As chamadas feitas com `Illuminate\Support\Facades\Http` aparecem como span `http.outbound:{host}` **e** na tabela de chamadas externas, com método, status e duração. Credenciais e query string são **removidas** da URL persistida por segurança:

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.stripe.secret'))
    ->get('https://api.stripe.com/v1/balance');
```

> Sem `pulse_start()` manual ao redor das chamadas — o middleware e os eventos do cliente HTTP cuidam de tudo. A captura cobre o cliente `Http` do Laravel; para Guzzle usado diretamente, adicione o `GuzzleMiddleware` (veja [Como instrumentar](#como-instrumentar-sua-aplicação)).

As rotas web e API recebem contexto com o nome da rota resolvida ou seu URI. O serviço usa `PULSE_SERVICE_NAME`, com fallback para `APP_NAME` e depois `default`. Bancos SQLite v1 existentes recebem as novas colunas na inicialização, sem apagar os registros anteriores.

---

## Configuração

| Variável / chave | Padrão | Descrição |
| --- | --- | --- |
| `pulse.enabled` | `true` | Liga/desliga toda a coleta. |
| `pulse.database_path` | `storage/pulse.sqlite` | Caminho do banco SQLite (`PULSE_DB_PATH`). |
| `pulse.service_name` | `default` | Nome do serviço exibido no dashboard. |
| `pulse.dashboard_path` | `pulse` | Rota do dashboard no Laravel (`PULSE_PATH`). O endpoint ao vivo fica em `{path}/data`. |
| `pulse.dashboard_middleware` | `['web']` | Middleware aplicado à rota do dashboard. |
| `pulse.dashboard_user` | `null` | Usuário do Basic Auth do dashboard (`.env: PULSE_DASHBOARD_USER`). |
| `pulse.dashboard_password` | `null` | Senha do Basic Auth do dashboard (`.env: PULSE_DASHBOARD_PASSWORD`). |
| `pulse.dashboard_allowed_ips` | `[]` | Whitelist de IPs do dashboard (`.env: PULSE_DASHBOARD_IPS`). |
| `pulse.collect_requests` | `true` | Captura de requisições web. |
| `pulse.collect_exceptions` | `true` | Captura de exceções. |
| `pulse.collect_queries` | `true` | Captura de consultas SQL. |
| `pulse.collect_outbound_requests` | `true` | Captura de chamadas HTTP externas (cliente `Http` do Laravel). |
| `pulse.collect_jobs` | `true` | Traces e spans para jobs de fila. |
| `pulse.sample_rate` | `1.0` | Fração de requisições/jobs gravados (`PULSE_SAMPLE_RATE`). Exceções sempre são gravadas. |
| `pulse.ignore_paths` | `/up,/health,...` | Caminhos nunca gravados, `*` é curinga (`PULSE_IGNORE_PATHS`). O dashboard é sempre ignorado. |
| `pulse.max_buffer_size` | `1000` | Registros em memória que forçam uma gravação antecipada. |
| `pulse.n_plus_one_threshold` | `5` | Repetições da mesma query num trace para sinalizar N+1. |
| `pulse.retention_days` | `7` | Dias de telemetria mantidos (`PULSE_RETENTION_DAYS`). |
| `pulse.prune_lottery` | `[1, 200]` | Chance de limpar dados antigos durante uma gravação. |
| `pulse.alerts.*` | desligado | Regras, janela, cooldown, webhook e e-mail dos alertas (`PULSE_ALERTS_ENABLED`, `PULSE_ALERT_*`). |

---

## 🧪 Testes

```bash
composer install
vendor/bin/phpunit
```

A suíte roda no GitHub Actions em **PHP 8.1 a 8.4**. Os testes de integração com Laravel (`--testsuite Laravel`) usam `orchestra/testbench` e rodam a partir do PHP 8.2.

Para alterar classes CSS do dashboard, recompile o stylesheet embutido (requer Node.js):

```bash
composer build:assets
```

```text
...........................................................  59 / 59 (100%)

OK (59 tests, 321 assertions)
```

---

## 🗺️ Roadmap

- [x] Núcleo standalone com buffer e flush em lote
- [x] Normalização e catalogação de SQL
- [x] Dashboard ao vivo com filtros e widgets
- [x] Integração opcional com Laravel 9+
- [x] Retenção configurável e limpeza do banco
- [x] Alertas por e-mail / webhook
- [x] Traces por request/job, detecção de N+1 e percentis
- [ ] Exportação de traces em formato OpenTelemetry
- [ ] Captura de comandos Artisan agendados

Veja os [issues abertos](https://github.com/jgcansi/pulse-php/issues) para o plano completo.

---

## 🤝 Contribuição

Contribuições são muito bem-vindas! Abra uma *issue* para discutir ideias ou envie um *pull request*.

```bash
# Fork -> branch -> commit -> pull request
git clone https://github.com/jgcansi/pulse-php.git
cd pulse-php
composer install
git checkout -b feat/minha-feature
git commit -m "feat: adiciona minha feature"
git push origin feat/minha-feature
```

1. Faça um **fork** do projeto.
2. Crie uma branch (`git checkout -b feat/minha-feature`).
3. Rode a suíte (`vendor/bin/phpunit`) antes de abrir o PR.
4. Abra um **pull request** descrevendo a mudança.

---

## 📄 Licença

Distribuído sob a **Licença MIT**. Veja [`LICENSE`](LICENSE) para detalhes.

Criado e mantido por **[João Gabriel Cansi Silveira](https://github.com/jgcansi)**.

<div align="center">

<p align="center">
  <a href="https://github.com/jgcansi/pulse-php/stargazers">
    <img src="https://img.shields.io/github/stars/jgcansi/pulse-php?style=social" alt="Stars">
  </a>
</p>

<br/>

**Se o PulsePHP foi útil pra você, deixe uma ⭐ — ajuda demais!**

</div>