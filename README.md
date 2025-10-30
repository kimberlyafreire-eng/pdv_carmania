# PDV Carmania

O PDV Carmania é uma interface web em PHP que integra o ponto de venda físico da Carmania ao ERP Bling. A aplicação centraliza o fluxo de venda, o controle de crediário, o gerenciamento de clientes e o acompanhamento do caixa, expondo cada etapa por meio de telas otimizadas para tablets e desktops. As telas consomem as APIs internas (diretamente integradas ao Bling) e persistem estados sensíveis, como carrinho e cliente selecionado, em `localStorage` para garantir continuidade do atendimento mesmo com oscilações de rede.

## Tecnologias e integrações

- **PHP 8** com sessões para autenticação e SQLite para cadastro de usuários internos.
- **Bootstrap 5** para a camada visual responsiva.
- **APIs do Bling** acessadas por endpoints internos (pasta `api/`) para produtos, estoque, clientes, vendas, crediário e caixa.
- **LocalStorage** do navegador para manter carrinho, descontos, cliente, depósito e recibos em andamento.
- **html2canvas** para transformar recibos HTML em imagens compartilháveis.

## Funcionalidades por tela

### Login (`app/login.php`)
- Validação de credenciais em SQLite com senha hash SHA-256 antes de abrir a sessão do usuário.【F:pdv_carmania/pdv_carmania/app/login.php†L5-L52】
- Redirecionamento automático para o PDV após autenticação e feedback visual para erros de login.【F:pdv_carmania/pdv_carmania/app/login.php†L13-L55】

### Vender (`app/index.php`)
- Catálogo em cards com busca inteligente por nome, código, código de barras ou GTIN; cada card indica preço e mantém a quantidade atual do carrinho.【F:pdv_carmania/pdv_carmania/app/index.php†L106-L350】
- Adição, remoção e contagem de itens persistidos no `localStorage`, com resumo fixo no desktop e barra de total móvel no celular.【F:pdv_carmania/pdv_carmania/app/index.php†L135-L227】【F:pdv_carmania/pdv_carmania/app/index.php†L230-L310】
- Identificação automática de código de barras ao pressionar Enter e fallback para filtragem textual, mantendo o destaque visual dos itens já incluídos.【F:pdv_carmania/pdv_carmania/app/index.php†L352-L402】
- Carregamento de produtos via `api/produtos-json.php` com tratamento de imagens ausentes e placeholders.【F:pdv_carmania/pdv_carmania/app/index.php†L240-L254】【F:pdv_carmania/pdv_carmania/app/index.php†L405-L425】

### Carrinho (`app/carrinho.php`)
- Recupera o estoque padrão do usuário no SQLite e apresenta ações rápidas para selecionar depósito, cliente e atualizar estoque em lote a partir do Bling.【F:pdv_carmania/pdv_carmania/app/carrinho.php†L8-L105】【F:pdv_carmania/pdv_carmania/app/carrinho.php†L392-L436】【F:pdv_carmania/pdv_carmania/app/carrinho.php†L951-L1056】【F:pdv_carmania/pdv_carmania/app/carrinho.php†L1016-L1079】
- Busca e cache incremental de clientes, com autocomplete tolerante a acentos e números, além de sincronização periódica com o ERP.【F:pdv_carmania/pdv_carmania/app/carrinho.php†L641-L823】
- Aplicação de desconto em valor ou percentual, persistindo a escolha e recalculando totais em tempo real.【F:pdv_carmania/pdv_carmania/app/carrinho.php†L917-L1130】
- Verificação prévia do status do caixa do depósito (data de abertura e situação) antes de permitir a ida para pagamento, garantindo conformidade operacional.【F:pdv_carmania/pdv_carmania/app/carrinho.php†L1199-L1305】

### Pagamento da venda (`app/pagamento.php`)
- Reaproveita carrinho, cliente, descontos e vendedor (quando configurado) para montar o resumo da venda a ser quitada.【F:pdv_carmania/pdv_carmania/app/pagamento.php†L370-L476】【F:pdv_carmania/pdv_carmania/app/pagamento.php†L758-L763】
- Controle de múltiplas formas de pagamento com validações específicas (ex.: cálculo automático de troco em dinheiro e bloqueio de excesso em cartões).【F:pdv_carmania/pdv_carmania/app/pagamento.php†L765-L879】
- Complementa dados do cliente consultando caches locais e endpoints do Bling quando documento ou endereço estão incompletos, garantindo emissão fiscal correta.【F:pdv_carmania/pdv_carmania/app/pagamento.php†L640-L744】
- Finaliza a venda enviando payload completo para `api/salvar-venda.php`, trata cenários de transmissão pendente e gera recibo com opções de imprimir, copiar ou compartilhar; exibe também o status da NF quando retornado pela API.【F:pdv_carmania/pdv_carmania/app/pagamento.php†L1009-L1112】

### Receber (`app/receber.php`)
- Autocomplete de clientes com busca por texto ou dígitos e consulta ao saldo do crediário via `api/crediario/saldo.php`.【F:pdv_carmania/pdv_carmania/app/receber.php†L74-L193】【F:pdv_carmania/pdv_carmania/app/receber.php†L218-L320】
- Lista títulos em aberto com valores, vencimentos e origem, permitindo abrir detalhes históricos de recebimentos e recibos anteriores.【F:pdv_carmania/pdv_carmania/app/receber.php†L321-L472】
- Gera relação completa do saldo (com totais e histórico) e disponibiliza compartilhamento como imagem usando `html2canvas`.【F:pdv_carmania/pdv_carmania/app/receber.php†L473-L574】
- Encaminha o cliente selecionado e os títulos para a tela de pagamento do crediário.【F:pdv_carmania/pdv_carmania/app/receber.php†L575-L608】

### Pagamento do crediário (`app/pagamento-crediario.php`)
- Reaproveita o cliente e os títulos selecionados na consulta de saldo para consolidar o valor a receber, mantendo depósito e usuário associados à baixa.【F:pdv_carmania/pdv_carmania/app/pagamento-crediario.php†L415-L475】
- Distribuição do recebimento em múltiplas formas de pagamento, com atualização dinâmica do que falta receber e bloqueio do botão de conclusão enquanto não há entradas.【F:pdv_carmania/pdv_carmania/app/pagamento-crediario.php†L360-L563】
- Registra a baixa via `api/crediario/baixar.php`, gera recibo compartilhável (impressão, cópia e compartilhamento nativo) e limpa o estado local após concluir.【F:pdv_carmania/pdv_carmania/app/pagamento-crediario.php†L565-L653】

### Vendas (`app/vendas.php`)
- Filtros por período, forma de pagamento e vendedor com carregamento assíncrono e mensagens de estado; usa backoff exponencial para lidar com respostas 429/503 do Bling.【F:pdv_carmania/pdv_carmania/app/vendas.php†L200-L237】【F:pdv_carmania/pdv_carmania/app/vendas.php†L310-L448】
- Exibe lista paginável com resumo das vendas filtradas e permite abrir painel detalhado contendo itens, formas de pagamento, vendedor e depósito utilizados, além de recibo renderizado em modal próprio.【F:pdv_carmania/pdv_carmania/app/vendas.php†L239-L308】

### Caixa (`app/caixa.php`)
- Detecta o depósito padrão do usuário e permite alternar entre depósitos disponíveis para acompanhar saldo, status (aberto/fechado) e resumo de movimentações.【F:pdv_carmania/pdv_carmania/app/caixa.php†L8-L100】【F:pdv_carmania/pdv_carmania/app/caixa.php†L172-L237】
- Cards de operações guiam abertura, fechamento, sangria e reforço; as ações são habilitadas/desabilitadas conforme o status retornado pela API.【F:pdv_carmania/pdv_carmania/app/caixa.php†L238-L312】
- Tabela de movimentações apresenta entradas e saídas com identificação do usuário e observações, facilitando conferência diária.【F:pdv_carmania/pdv_carmania/app/caixa.php†L120-L166】【F:pdv_carmania/pdv_carmania/app/caixa.php†L314-L372】

### Produtos (`app/produtos.php`)
- Consulta catálogo com filtros por depósito, exibindo estoque consolidado e detalhamento por depósito em linha expansível.【F:pdv_carmania/pdv_carmania/app/produtos.php†L1-L122】【F:pdv_carmania/pdv_carmania/app/produtos.php†L180-L250】
- Permite atualizar rapidamente o estoque em tela e sinaliza visualmente itens com baixo saldo usando badges e destaques.【F:pdv_carmania/pdv_carmania/app/produtos.php†L122-L178】【F:pdv_carmania/pdv_carmania/app/produtos.php†L250-L320】

## Considerações operacionais

- Os recibos gerados em vendas e baixas de crediário são cruciais para a rotina: ambos utilizam `html2canvas` para permitir impressão, cópia e compartilhamento diretamente dos navegadores suportados.【F:pdv_carmania/pdv_carmania/app/pagamento.php†L1044-L1112】【F:pdv_carmania/pdv_carmania/app/pagamento-crediario.php†L598-L653】
- Como o Bling pode aplicar rate limit agressivo, a tela de Vendas implementa cancelamento de requisições duplicadas e backoff exponencial para evitar travamentos durante a filtragem.【F:pdv_carmania/pdv_carmania/app/vendas.php†L310-L448】
- O projeto mantém caches locais (clientes, depósitos, carrinho, descontos) para reduzir dependência de rede durante o atendimento, mas valida estados críticos — como caixa aberto — junto ao ERP antes de concluir uma venda.【F:pdv_carmania/pdv_carmania/app/carrinho.php†L641-L1305】【F:pdv_carmania/pdv_carmania/app/pagamento.php†L1009-L1112】

