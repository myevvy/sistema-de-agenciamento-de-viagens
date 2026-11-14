# Agência de viagens

Sistema web em PHP para gerenciar clientes, pacotes de viagem e reservas. Os dados são armazenados no MySQL e podem ser visualizados pelo phpMyAdmin.

## Requisitos

- PHP 8.1 ou superior com a extensão `pdo_mysql`
- MySQL Server
- phpMyAdmin (opcional, para administrar o banco pela interface web)

Confira se o driver está disponível no PHP que vai executar o site:

```bash
php -r 'echo PHP_BINARY, PHP_EOL; print_r(PDO::getAvailableDrivers());'
```

O resultado precisa incluir `mysql`. Instale o driver correspondente à versão do PHP em uso (por exemplo, `sudo apt install php-mysql` em instalações PHP gerenciadas pelo Ubuntu). Em containers Ubuntu com PHP 8.3, pode ser necessário instalar `php8.3-cli php8.3-mysql` e usar `php8.3` nos comandos abaixo. O driver instalado para uma versão do PHP não habilita automaticamente outra versão.

## Configuração do MySQL

1. No phpMyAdmin, crie o banco `agencia_viagens` com collation `utf8mb4_unicode_ci`. Também é possível executar no separador SQL:

   ```sql
   CREATE DATABASE agencia_viagens
     CHARACTER SET utf8mb4
     COLLATE utf8mb4_unicode_ci;
   ```

2. Crie o arquivo local de configuração e ajuste usuário e senha:

   ```bash
   cp config.example.php config.php
   ```

   O arquivo `config.php` é ignorado pelo Git, para não publicar credenciais. O usuário do MySQL precisa ter permissão para criar tabelas no banco `agencia_viagens`.

3. Inicie o sistema:

   ```bash
   php -S localhost:8000
   ```

   Abra <http://localhost:8000>. As tabelas são criadas automaticamente na primeira execução. Se a conexão falhar, confira se o MySQL está ativo, se `pdo_mysql` está habilitado e se host, porta, banco e credenciais em `config.php` estão corretos.

Esta versão não possui autenticação; use em ambiente local/interno e implemente login e controle de acesso antes de publicar na internet.

## Funcionalidades

- Painel com totais de clientes, pacotes e reservas.
- Cadastro, edição e remoção de clientes e pacotes.
- Cadastro de reservas associando clientes e pacotes, com controle de vagas disponíveis.
- Atualização do estado da reserva: pendente, confirmada ou cancelada.
- Proteção CSRF nos formulários, validação no servidor e consultas preparadas.

Pacotes e clientes vinculados a reservas não podem ser removidos; remova primeiro as reservas relacionadas.

Se você já cadastrou dados na versão SQLite anterior, eles não são copiados automaticamente para o MySQL; a base SQLite original permanece em `storage/agencia.sqlite`.

## Solução de problemas

### O container informa que o systemd não está rodando

Em containers sem systemd, inicie e confira o MySQL com `service`:

```bash
sudo service mysql start
sudo service mysql status
```

Se o comando `php` não listar o driver `mysql`, use a versão que tem `pdo_mysql` habilitado para iniciar o servidor. Por exemplo:

```bash
php8.3 -S localhost:8000
```

### A porta 8000 já está em uso

Já existe outro servidor nessa porta. Pare-o com `Ctrl+C` no terminal em que está rodando e inicie o PHP novamente, ou escolha outra porta:

```bash
php8.3 -S localhost:8001
```

Nesse caso, abra <http://localhost:8001>.

### `could not find driver` ou a lista PDO não inclui `mysql`

O `pdo_mysql` não está habilitado na versão do PHP que está executando a aplicação. Instale/habilite o driver para essa versão e confira novamente com `PDO::getAvailableDrivers()`. Reinicie o servidor PHP depois de instalar o driver.

### `SQLSTATE[HY000] [1045] Access denied`

O MySQL recusou usuário ou senha. Confira os valores de `username` e `password` em `config.php`; não compartilhe esse arquivo nem publique a senha. Como administrador do MySQL, redefina a senha e conceda acesso ao banco. Exemplo para o usuário local:

```sql
CREATE USER IF NOT EXISTS 'agencia_user'@'localhost'
  IDENTIFIED BY 'SUBSTITUA_PELA_SENHA';
ALTER USER 'agencia_user'@'localhost'
  IDENTIFIED BY 'SUBSTITUA_PELA_SENHA';
GRANT ALL PRIVILEGES ON agencia_viagens.*
  TO 'agencia_user'@'localhost';
```

Substitua o texto da senha nas duas instruções e use exatamente a mesma senha em `config.php`. Para testar a autenticação via TCP sem colocar a senha no comando:

```bash
mysql --protocol=TCP -h 127.0.0.1 -P 3306 -u agencia_user -p
```

O cliente pedirá a senha. Após conectar, confira o banco com `SHOW DATABASES;`.

### `ERROR 1049 (42000): Unknown database 'agencia_viagens'`

O servidor aceitou o usuário, mas não encontrou o banco. Crie-o no mesmo servidor MySQL ao qual a aplicação está conectada e conceda acesso:

```sql
CREATE DATABASE IF NOT EXISTS agencia_viagens
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON agencia_viagens.*
  TO 'agencia_user'@'localhost';
```

Confira também se `database` em `config.php` está escrito exatamente como `agencia_viagens`. O sistema cria as tabelas automaticamente quando conecta.

### `SQLSTATE[HY000] [2002] Permission denied`

Em containers, `localhost` pode tentar usar um socket Unix que o PHP não consegue acessar. Tente `127.0.0.1` em `host` no `config.php`, que usa TCP, e confirme que o serviço MySQL está ativo. Use também `-h 127.0.0.1` ao testar com o cliente `mysql`.
