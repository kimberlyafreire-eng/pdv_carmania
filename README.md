# PDV Carmania

O PDV Carmania é uma interface web que integra o ponto de venda da Carmania ao ERP Bling. O projeto foi criado para fornecer uma experiência de uso mais fluida e intuitiva do que a interface nativa do Bling.

## Visão Geral do Fluxo
- **index.php**: tela principal onde a venda é montada, com catálogo de produtos em cards, controle de quantidades e resumo do carrinho (lateral no desktop, inferior no mobile).
- **carrinho.php**: permite selecionar cliente, estoque de baixa, atualizar dados diretamente do Bling, limpar carrinho e aplicar descontos por valor ou percentual.
- **pagamento.php**: apresenta os métodos de pagamento, validando que 100% do valor seja distribuído entre eles. A opção **Crediário** cria um título em Contas a Receber no Bling para controle de fiado.
- **receber.php**: consulta e dá baixa nos títulos de Crediário, priorizando os mais antigos e respeitando os valores pagos.

Após finalizar uma venda, o sistema registra o pedido no Bling, atualiza o estoque e cria os lançamentos de Contas a Receber correspondentes.

## Itens Importantes
- As imagens dos produtos ainda não são exibidas porque o salvamento no diretório `imagens/` não está concluído.
- Recibos são gerados tanto na venda quanto no recebimento via Crediário e fazem parte essencial do processo operacional.

## Próximos Passos
O foco das próximas tarefas está em evoluir a UX e a UI sem comprometer as funcionalidades já existentes, adicionando gradualmente novos fluxos que otimizem a operação do PDV.

## Erro 429 ao baixar recursos do GitHub
Desde a segunda quinzena de janeiro de 2025, diversos provedores brasileiros estão enfrentando bloqueios intermitentes ao acessar domínios do GitHub, em especial `raw.githubusercontent.com`. Isso provoca falhas intermitentes em instalações via scripts ou nos pipelines do GitHub Actions, mesmo quando o limite de requisições não foi oficialmente excedido.

Para mitigar o impacto enquanto a situação não é normalizada, recomenda-se:

- Configurar os runners (inclusive self-hosted) para sempre expor a variável `GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}` nos jobs. Em muitos casos isso evita a limitação agressiva de IP e amplia os limites padrão de rate limit.
- Repetir etapas críticas usando `continue-on-error: true` seguido de um passo condicional com `if: failure()` para tentar novamente downloads que falharem com `429`. Essa abordagem é útil para ações públicas que não permitem personalização de backoff.
- Quando houver necessidade de chamadas manuais (scripts próprios), implementar backoff exponencial e respeitar os cabeçalhos `X-RateLimit-Remaining`/`X-RateLimit-Reset`. O servidor do GitHub sinaliza claramente quando o limite foi atingido com HTTP 403 e esses cabeçalhos.
- Caso o bloqueio seja devido ao roteamento nacional, considerar temporariamente o uso de VPN ou proxies corporativos até que o provedor normalize o acesso.
- Para consultas internas no PDV, a tela **Vendas** agora cancela requisições em duplicidade, utiliza backoff exponencial quando o servidor responde com `429/503` e desabilita o botão de filtro enquanto aguarda a resposta, reduzindo a chance de excesso de chamadas sequenciais.

Essas medidas foram consolidadas a partir dos relatos recentes da comunidade brasileira de desenvolvimento e ajudam a reduzir a instabilidade até a regularização do acesso ao GitHub.
