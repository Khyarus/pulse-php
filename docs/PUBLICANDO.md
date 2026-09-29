# 🚀 Guia de Publicação no Packagist

Este guia mostra, passo a passo, como publicar o **PulsePHP** no [Packagist.org](https://packagist.org) — o repositório central de pacotes do Composer — e como configurar atualizações automáticas via webhook do GitHub.

> **Pré-requisitos:** repositório no GitHub com `composer.json` válido na raiz, licença (`LICENSE`) e um commit inicial já enviado (`git push`).

---

## 1. Prepare o repositório

Antes de publicar, garanta que o pacote está pronto:

```bash
# Valide o composer.json em modo estrito
composer validate --strict

# Confirme que a suíte de testes está verde
composer test
```

Verifique o `composer.json`:

- `name` está no formato `vendor/package` (ex.: `jgcansi/pulse-php`).
- `description` é curta e objetiva.
- `license` está definida (`MIT`).
- `authors` preenchido com nome e e-mail.
- `autoload` PSR-4 aponta para `src/`.

---

## 2. Crie uma conta no Packagist

1. Acesse **[https://packagist.org](https://packagist.org)**.
2. Clique em **Sign up** (ou faça login com a sua conta do **GitHub**, mais rápido).
3. Confirme o e-mail de verificação, se solicitado.

---

## 3. Conecte o repositório GitHub ao Packagist

1. Faça login no Packagist.
2. No menu superior, clique em **Submit**.
3. Cole a URL do repositório no campo **Repository URL**:

   ```
   https://github.com/jgcansi/pulse-php
   ```

4. Clique em **Check**. O Packagist lê o `composer.json`, apresenta o nome do pacote e verifica se ele já existe.
5. Se o nome estiver livre, clique em **Submit** para publicar.

> ✅ **Pronto!** O pacote estará disponível para instalação:

```bash
composer require jgcansi/pulse-php
```

> ℹ️ Como esta é uma versão de pré-lançamento, usuários precisarão permitir instabilidade:
>
> ```bash
> composer require jgcansi/pulse-php:^1.0@beta
> ```

---

## 4. Configure o Webhook do GitHub (atualizações automáticas)

Para que o Packagist atualize sozinho sempre que você der `push` de uma nova tag/commit, configure o webhook:

1. No **GitHub**, vá em: **Settings → Webhooks → Add webhook**
   (`https://github.com/jgcansi/pulse-php/settings/hooks`).
2. **Payload URL** (pegue a sua URL de API no Packagist, exibida em **Profile → Show API Token**):

   ```
   https://packagist.org/api/github?username=SEU_USUARIO_PACKAGIST
   ```

3. **Content type:** `application/json`.
4. **Secret:** deixe vazio (a autenticação é feita via token do Packagist).
5. **Which events would you like to trigger this webhook?** → `Just the push event`.
6. Marque **Active** e clique em **Add webhook**.

### Alternativa via API do Packagist

Se preferir, o próprio Packagist fornece a URL pronta do webhook na aba **Settings** do pacote. Copie a **Payload URL** e cole no campo correspondente no GitHub (passo 2 acima).

---

## 5. Publique uma release com tags do Git

O Packagist **não** cria versões sozinho: ele as deriva das **tags do Git**. Ao enviar uma tag no formato SemVer, o Packagist cria a release automaticamente (graças ao webhook).

```bash
# 1. Garanta que está na branch main e atualizado
git checkout main
git pull origin main

# 2. Crie uma tag anotada para a versão Beta 1
git tag -a v1.0.0-beta.1 -m "Lançamento da versão Beta 1"

# 3. Envie a tag para o GitHub
git push origin v1.0.0-beta.1
```

> 💡 **Por que tag anotada (`-a`)?** Ela guarda autor, data e mensagem — ideal para releases. Uma tag leve (`git tag v1.0.0-beta.1`) só aponta para um commit.

Após o push, em segundos:

1. O GitHub dispara o webhook.
2. O Packagist processa a tag e cria a versão `1.0.0-beta.1`.
3. O pacote passa a ser instalável nessa versão.

### Como o Packagist interpreta as tags

| Tag do Git | Versão no Packagist |
| --- | --- |
| `v1.0.0-beta.1` | `1.0.0.0-beta1` (pré-lançamento) |
| `v1.0.0-rc.1` | `1.0.0.0-RC1` |
| `v1.0.0` | `1.0.0.0` (estável) |
| `v1.1.0` | `1.1.0.0` |

> O prefixo `v` é opcional e ignorado na comparação de versões. **Mantenha o padrão** (com ou sem `v`) em todas as tags.

### Atualizar o GitHub Releases (opcional, recomendado)

Para gerar também uma **Release** no GitHub com notas de versão:

1. Vá em **Releases → Draft a new release**.
2. Selecione a tag `v1.0.0-beta.1`.
3. Use o título `v1.0.0-beta.1` e cole a seção correspondente do [`CHANGELOG.md`](../CHANGELOG.md).
4. Marque **Set as a pre-release** (por ser Beta).
5. Clique em **Publish release**.

---

## 6. Verifique a publicação

```bash
# Consultar informações do pacote publicado
composer show jgcansi/pulse-php --all

# Instalar em um projeto de teste
composer require jgcansi/pulse-php:^1.0@beta
```

Também confira no navegador:

- Página do pacote: `https://packagist.org/packages/jgcansi/pulse-php`
- Versões: `https://packagist.org/packages/jgcansi/pulse-php#versions`

---

## 7. Checklist de lançamento

- [ ] `composer validate --strict` sem erros.
- [ ] `composer test` verde.
- [ ] `CHANGELOG.md` atualizado com a seção da nova versão.
- [ ] Versão coerente com o [SemVer](#📐-como-o-versionamento-funciona-semver).
- [ ] Tag anotada criada e enviada (`git push origin vX.Y.Z`).
- [ ] Webhook do GitHub configurado e ativo.
- [ ] Versão apareceu no Packagist.
- [ ] GitHub Release publicada (pré-release marcada quando aplicável).

---

## 🔒 Segurança

- **Nunca** faça commit de tokens, `.env` ou segredos.
- Rotacione o **API Token** do Packagist em **Profile → Show API Token** se ele vazar.
- Proteja a branch `main` com regras de revisão de PR (GitHub → Settings → Branches).

---

Voltar para o [README](../README.md).
