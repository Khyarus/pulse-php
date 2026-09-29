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
- (em desenvolvimento)

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

[Unreleased]: https://github.com/jgcansi/pulse-php/compare/v1.0.0-beta.1...HEAD
[1.0.0-beta.1]: https://github.com/jgcansi/pulse-php/releases/tag/v1.0.0-beta.1
