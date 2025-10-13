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
