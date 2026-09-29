# Contribuindo com o PulsePHP

Primeiramente, muito obrigado por dedicar seu tempo a contribuir! 🎉

Este documento descreve o fluxo de trabalho, os padrões de código e as convenções de commit adotadas pelo projeto. Seguir essas diretrizes mantém o histórico limpo, previsível e facilita a manutenção.

---

## 📋 Código de Conduta

Ao participar deste projeto, você concorda em manter um ambiente respeitoso, acolhedor e livre de assédio. Seja gentil nas revisões: revise o código, não a pessoa.

---

## 🐛 Reportando Bugs

Antes de abrir uma issue, **pesquise as [issues existentes](https://github.com/jgcansi/pulse-php/issues)** para evitar duplicatas.

Use o template [**Bug Report**](.github/ISSUE_TEMPLATE/bug_report.md) e inclua:

- Versão do pacote (`composer show jgcansi/pulse-php`).
- Versão do PHP (`php -v`) e do Laravel (se aplicável).
- Sistema operacional e versão da extensão `pdo_sqlite`.
- Passos claros para reproduzir o problema.
- Comportamento esperado vs. observado.
- Logs, stack trace e captura de tela, se possível.

---

## 💡 Sugerindo Funcionalidades

Use o template [**Feature Request**](.github/ISSUE_TEMPLATE/feature_request.md). Descreva o **problema** que você quer resolver antes de propor a **solução** — isso ajuda a alinhar expectativas antes de escrever código.

---

## 🚀 Fluxo de Trabalho (Branches)

Adotamos um fluxo baseado em branches curtas e objetivas:

| Branch | Finalidade |
| --- | --- |
| `main` | Código estável. **Toda release parte daqui.** Nunca commite direto nela. |
| `feature/<descricao>` | Novas funcionalidades. |
| `fix/<descricao>` | Correções de bugs. |
| `docs/<descricao>` | Alterações apenas em documentação. |
| `refactor/<descricao>` | Refatorações sem mudança de comportamento. |

### Passo a passo

```bash
# 1. Faça um fork do repositório no GitHub e clone o seu fork
git clone https://github.com/<seu-usuario>/pulse-php.git
cd pulse-php

# 2. Adicione o repositório oficial como upstream
git remote add upstream https://github.com/jgcansi/pulse-php.git

# 3. Sincronize a branch main
git checkout main
git pull upstream main

# 4. Crie uma branch descritiva
git checkout -b feature/minha-feature

# 5. Instale dependências e garanta que a suíte está verde antes de começar
composer install
composer test

# 6. Faça suas mudanças, escreva/atualize testes e commite (veja o padrão abaixo)
git add .
git commit -m "feat(dashboard): adiciona filtro por faixa de latência"

# 7. Rode a verificação completa antes de enviar
composer check

# 8. Envie para o seu fork
git push origin feature/minha-feature
```

Depois, abra um **Pull Request** para a branch `main` do repositório oficial.

---

## 📝 Padrão de Commits (Conventional Commits)

Usamos o padrão [**Conventional Commits**](https://www.conventionalcommits.org/pt-br/v1.0.0/). O formato é:

```text
<tipo>(<escopo opcional>): <descrição imperativa e curta>
```

### Tipos aceitos

| Tipo | Quando usar |
| --- | --- |
| `feat` | Uma **nova funcionalidade** para o usuário. |
| `fix` | Uma **correção de bug** para o usuário. |
| `docs` | Alterações **apenas em documentação** (README, CONTRIBUTING, etc.). |
| `refactor` | Mudança de código que **não corrige bug nem adiciona feature**. |
| `test` | Adição ou correção de **testes**. |
| `chore` | Tarefas de manutenção, build, dependências — **sem impacto no código de produção**. |
| `perf` | Melhoria de **performance**. |
| `style` | Formatação, espaços, ponto e vírgula — sem mudança de lógica. |

### ✅ Exemplos válidos

```text
feat: adiciona captura de chamadas ao cliente Http do Laravel
feat(dashboard): exibe sparklines de latência por rota
fix: corrige flush do buffer em processos de longa duração
fix(storage): evita duplicação de queries normalizadas idênticas
docs: documenta variáveis de ambiente no README
refactor(collectors): extrai resolução de rota para um helper
test(unit): cobre normalização de SQL com placeholders
chore(deps): atualiza phpunit para ^10.5
```

### ❌ Exemplos inválidos

```text
atualizei umas coisas                    # sem tipo e vago
update                                   # sem tipo nem descrição
Feat: Adiciona nova feature              # tipo em maiúscula
feat: Adiciona nova feature.             # verbo conjugado + ponto final
fixed bug                                # tipo errado ("fix", não "fixed")
feat:                                  # sem descrição
```

### Regras de ouro da descrição

- Use o **imperativo** ("adiciona", não "adicionei" / "adicionando").
- Comece com **minúscula**.
- Seja **curto** (ideal ≤ 72 caracteres) e objetivo.
- **Sem ponto final**.
- Use o escopo entre parênteses quando ajudar a localizar a mudança (`dashboard`, `storage`, `collectors`, `laravel`).

### Commits que quebram compatibilidade

Para mudanças incompatíveis (breaking changes), adicione `!` após o tipo/escopo **e** o rodapé `BREAKING CHANGE`:

```text
feat(storage)!: altera assinatura de StorageInterface::record()

BREAKING CHANGE: o segundo parâmetro de record() deixou de ser opcional.
Atualize suas implementações de StorageInterface.
```

---

## 🧪 Testes

Toda contribuição de código **deve** vir acompanhada de testes.

- Testes unitários ficam em `tests/Unit/`.
- Testes de integração ficam em `tests/Integration/`.
- A suíte roda em **PHP 8.1, 8.2 e 8.3** no CI (GitHub Actions).

```bash
# Rodar a suíte completa
composer test

# Rodar com cobertura HTML (gera build/coverage)
composer test:coverage

# Rodar um único arquivo
vendor/bin/phpunit tests/Unit/PulseTest.php
```

---

## 🎨 Estilo de Código

Seguimos o [**PSR-12**](https://www.php-fig.org/psr/psr-12/) e usamos o PHP-CS-Fixer para garantir o padrão.

```bash
# Apenas verifica (não altera arquivos) — usado no CI
composer check-style

# Aplica as correções automaticamente
composer format
```

Antes de abrir um PR, rode a verificação completa:

```bash
composer check
```

Esse comando executa, em sequência: validação do `composer.json`, verificação de estilo e todos os testes.

---

## 📌 Checklist do Pull Request

Antes de enviar, confirme que:

- [ ] O código segue o PSR-12 (`composer check-style` passa).
- [ ] Adicionei/atualizei testes para cobrir a mudança.
- [ ] A suíte completa está verde (`composer test`).
- [ ] Atualizei a documentação relevante (README, PHPDoc, etc.).
- [ ] Adicionei uma entrada no [`CHANGELOG.md`](CHANGELOG.md) em `[Unreleased]`.
- [ ] Os commits seguem o padrão Conventional Commits.
- [ ] O título do PR resume a mudança claramente.

---

## 🏷️ Lançamentos (para mantenedores)

O versionamento segue o [**SemVer**](https://semver.org/lang/pt-BR/). Veja o passo a passo de publicação no [`docs/PUBLICANDO.md`](docs/PUBLICANDO.md).

---

Obrigado por contribuir com o **PulsePHP**! 🚀
