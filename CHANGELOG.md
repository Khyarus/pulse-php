# Changelog

Todas as mudanças relevantes deste projeto são documentadas neste arquivo.

O formato segue o [**Keep a Changelog**](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adere ao [**Versionamento Semântico (SemVer)**](https://semver.org/lang/pt-BR/).

---

## 📐 Como o versionamento funciona (SemVer)

As versões do PulsePHP seguem o formato **`MAJOR.MINOR.PATCH`**, com um sufixo opcional de pré-lançamento:

```
     1   .   0   .   0   -   beta.1
     │       │       │        │
   MAJOR   MINOR   PATCH   PRÉ-LANÇAMENTO
```

| Parte | Quando é incrementada | Exemplo |
| --- | --- | --- |
| **MAJOR** | Mudanças **incompatíveis** com versões anteriores (breaking changes). | `1.4.2` → `2.0.0` |
| **MINOR** | **Novas funcionalidades** compatíveis com versões anteriores. | `1.4.2` → `1.5.0` |
| **PATCH** | **Correções de bugs** compatíveis. | `1.4.2` → `1.4.3` |
| **PRÉ-LANÇAMENTO** | Versões instáveis em preparação (`alpha`, `beta`, `rc`). | `1.0.0-beta.1` |

### Pré-lançamentos

- `1.0.0-alpha.1` — primeiros testes, API ainda instável.
- `1.0.0-beta.1` — **funcionalidades completas**, sujeito a correções antes do estável.
- `1.0.0-rc.1` ([release candidate](https://pt.wikipedia.org/wiki/Software_release_life_cycle)) — candidata a estável; apenas correções críticas são aceitas.
- `1.0.0` — versão estável.

> Pré-lançamentos têm **menor precedência** que a versão final correspondente: `1.0.0-beta.1 < 1.0.0-rc.1 < 1.0.0`.

---

## [Unreleased]

### Adicionado
- **Dashboard redesenhado em telas:** Overview, Requests, Errors (lista e detalhe), Queries, Jobs, Outbound HTTP, Logs e Storage & settings, com navegação lateral e escopo (período, serviço, rota) compartilhado.
- **Overview com sinais vitais** (requisições, p95, taxa de 5xx, exceções) e comparação com o período anterior; gráfico de requisições e exceções sobre papel de eletrocardiograma.
- **Trace em cascata:** spans, queries e chamadas externas posicionados no tempo (novas colunas `offset_ms`), mais exceções e logs da execução.
- **Ciclo de vida dos erros:** resolver, ignorar e reabrir pelo dashboard; um erro resolvido que volta a acontecer aparece como *regressed*. Formulários com proteção CSRF (token do Laravel ou cookie próprio no modo standalone).
- **Logs:** `Pulse::recordLog()`, `PulsePHP\Logging\PulseLogHandler` (Monolog 3) e captura automática no Laravel a partir de `PULSE_LOG_LEVEL` (padrão `warning`).
- **Marcadores de deploy:** `Pulse::deploy()`, `php artisan pulse:deploy` e `vendor/bin/pulse deploy`, desenhados nos gráficos.
- **Busca Ctrl+K** por telas, rotas, serviços e erros; **tema claro e escuro** (segue o sistema ou escolha manual); horários exibidos no fuso do navegador.
- `Dashboard::withRequest()` para frameworks passarem a requisição explicitamente (necessário no Octane e em testes, onde as superglobais não são preenchidas). O controller do Laravel já usa.

### Alterado
- O CSS do Tailwind e a etapa de build em Node foram substituídos por um CSS próprio. Fontes IBM Plex embutidas; assets servidos via `?asset=` com cache longo em vez de inline em cada página.
- O endpoint JSON ao vivo agora devolve `vitals` e um `chartData` no formato `{labels, requests, exceptions, deploys}`.
- O gerador de tráfego de exemplo mede tempos reais, simula um N+1, grava logs e marca um deploy.

---

## [1.0.1] - 2026-10-13

### Corrigido
- Links do GitHub (`composer.json`, README, CHANGELOG, guias e templates de issue) apontavam para `github.com/jgcansi/pulse-php`, que não existe. Agora apontam para `github.com/Khyarus/pulse-php`. O nome do pacote no Composer continua `jgcansi/pulse-php`.
- Ilustração do terminal no README mostrava a instalação da versão beta.

---

## [1.0.0] - 2026-10-13

Primeira versão **estável**. A API pública segue SemVer a partir daqui.

### Corrigido
- **Exceções no Laravel não eram capturadas.** O Laravel renderiza a exceção dentro do pipeline de rotas, então o middleware nunca a via. A captura agora usa o `ExceptionHandler` (`reportable`), cobre HTTP, jobs e console e respeita o `dontReport` (404, validação etc. não viram ruído).
- **Auto-refresh do dashboard quebrado no Laravel.** O JS chamava `dashboard-data.php`, que não existe no Laravel. Nova rota `GET /pulse/data` (`pulse.data`), e a URL é configurável via `Dashboard::withDataEndpoint()`.
- **Middleware fora do grupo `web`/`api` no Laravel 11+.** O kernel re-sincroniza os grupos e descartava o middleware adicionado só no router; agora ele é registrado pelo kernel.
- **Vazamento de memória em workers (filas, Octane, Horizon).** O buffer só era gravado no shutdown do PHP. Agora é gravado ao fim de cada request (`terminating`, `RequestTerminated`), ao fim de cada job e sempre que atinge `max_buffer_size`.
- **Falhas de gravação derrubavam a aplicação.** `flush()` não lança mais exceções: o lote é descartado e o erro vai para o log (`reportErrorsUsing()` no core, `Log::warning` no Laravel).
- **Exceções não tratadas eram engolidas no modo standalone** quando não havia handler anterior: o script terminava com status 0 e sem log. Agora o erro é logado e o processo sai com status 255 (HTTP 500).
- **O dashboard registrava as próprias requisições**, poluindo as métricas. Dashboard e endpoint de dados são sempre ignorados.
- **Dashboard falhava antes da primeira gravação** (banco inexistente). O banco é criado e migrado ao abrir o dashboard.
- Tabela de exceções do dashboard sem `<tbody>`.

### Adicionado
- **Retenção:** `pulse:prune` (Laravel), `vendor/bin/pulse prune` (standalone) e limpeza automática por loteria (`retention_days`, `prune_lottery`). Remove entradas órfãs do catálogo de SQL e aceita `--vacuum`.
- **Sampling** (`sample_rate`) e **lista de caminhos ignorados** (`ignore_paths`, com `*`). Exceções e métricas explícitas são sempre gravadas.
- **Traces:** cada request ou job recebe um `trace_id` que correlaciona request, queries, spans, chamadas externas e exceções. Nova **página de detalhe** (`?trace=<id>`).
- **Detecção de N+1:** padrões de query repetidos dentro do mesmo trace (limite configurável em `n_plus_one_threshold`).
- **Exceções agrupadas** por classe + arquivo + linha, com contagem, última mensagem e link para o trace. A classe da exceção agora é salva.
- **Percentis p95/p99** de latência no resumo e p95 por rota.
- **Alertas** por webhook (JSON genérico ou Slack) e e-mail (Laravel): taxa de 5xx, volume de exceções e p95, com janela e cooldown. `pulse:check` / `vendor/bin/pulse check`.
- **Jobs de fila** viram traces próprios (`job:<Classe>`) com span de duração.
- **`PulsePHP\Http\GuzzleMiddleware`** para capturar chamadas Guzzle.
- **`PulsePHP\Database\TracedPDO`** para capturar queries em PHP puro (só o SQL normalizado; valores nunca são gravados).
- **`php artisan pulse:install`**, que publica a config e mostra os passos restantes.
- Novas chaves de config e variáveis de ambiente: `PULSE_DB_PATH`, `PULSE_PATH`, `PULSE_SAMPLE_RATE`, `PULSE_IGNORE_PATHS`, `PULSE_RETENTION_DAYS`, `PULSE_ALERT_*`, entre outras.
- Testes de integração Laravel com `orchestra/testbench` e CI em PHP 8.1–8.4.

### Alterado
- **Assets do dashboard embutidos no pacote** (Chart.js 4.4.7, Alpine.js 3.14.8 e CSS do Tailwind compilado). Nenhum CDN nem Google Fonts: o dashboard funciona offline e é compatível com CSP sem domínios externos.
- **A query string não é mais gravada** nas URLs de requisições recebidas (podia conter tokens ou dados pessoais), igual às chamadas externas.
- `StorageInterface` ganhou `prune()`.
- `Pulse::flush()` não relança exceções de storage.
- Bancos existentes são migrados automaticamente (novas colunas `trace_id`, `class`, `fingerprint` e índices), sem perder dados.

---

## [1.0.0-beta.1] - 2026-01-01

Primeira versão **Beta** pública. As funcionalidades centrais estão completas e a API é considerada estável o suficiente para uso em testes, sujeita a ajustes até o lançamento `1.0.0` estável.

### Adicionado
- Núcleo **standalone** para PHP 8.1+ com inicialização via `Pulse::init()`.
- **Buffer em memória** com gravação em lote (flush) no encerramento do processo.
- **Normalização e catalogação de SQL**, agrupando consultas equivalentes.
- **Timers** para medir duração e variação de memória de operações (`pulse_start` / `pulse_end`).
- **Captura automática** de requisições web e exceções via coletores.
- **Contexto de serviço e rota** em cada requisição registrada.
- **Chamadas SQL explícitas** via `Pulse::getInstance()->recordQuery()`.
- **Dashboard ao vivo** em HTML + JS com filtros por período, serviço e rota.
- **Gráficos e sparklines** de throughput e latência por serviço e rota.
- **Widgets** de slow queries, slow spans e chamadas externas (*outbound*).
- **Cartões de saúde por rota**, sinalizando degradação.
- **Painel de erros e exceções** com classe, rota e serviço.
- **Armazenamento local em SQLite** (arquivo único, sem infraestrutura externa).

#### Integração com Laravel 9+
- **Service provider** com auto-discovery via Composer.
- Captura automática do cliente `Illuminate\Support\Facades\Http` como span `http.outbound:{host}` e como chamada externa.
- **Remoção de credenciais e query string** das URLs persistidas por segurança.
- Migração automática de bancos SQLite v1 existentes, sem apagar dados.

#### Segurança
- **Dashboard negado por padrão**: exige configuração de política de acesso (Gate `viewPulse`, Basic Auth e/ou whitelist de IPs).

#### Documentação e Qualidade
- README completo com demonstração, instalação, uso e integração com Laravel.
- Suíte de testes com **PHPUnit** executada em **PHP 8.1, 8.2 e 8.3** no GitHub Actions.
- Templates de issue, Pull Request e `CONTRIBUTING.md`.

### Observações
- Versão de **pré-lançamento**: a API pode sofrer pequenos ajustes antes de `1.0.0`.
- Para instalá-la com o Composer, é necessário permitir versões instáveis:

```bash
composer require jgcansi/pulse-php:^1.0@beta
```

---

[Unreleased]: https://github.com/Khyarus/pulse-php/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/Khyarus/pulse-php/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Khyarus/pulse-php/compare/v1.0.0-beta.1...v1.0.0
[1.0.0-beta.1]: https://github.com/Khyarus/pulse-php/releases/tag/v1.0.0-beta.1
