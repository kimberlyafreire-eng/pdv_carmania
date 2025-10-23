<?php
return [
    // Habilita ou desabilita a tentativa automática de criar a NF-e ao concluir a venda.
    'habilitado' => true,

    // Quando falso (padrão), o sistema apenas cria o rascunho da NF-e no Bling sem transmitir para a SEFAZ.
    // Ajuste para true somente se desejar transmitir automaticamente após a criação.
    'transmitir_automaticamente' => false,

    // Tipo da NF-e (1 = Saída, 0 = Entrada). Ajuste conforme o cenário desejado.
    'tipo' => 1,

    // Finalidade padrão da NF-e (1 = Normal). Consulte a documentação do Bling para outros valores.
    'finalidade' => 1,

    // Identificador da natureza de operação cadastrada no Bling.
    // Deixe como null caso não deseje enviá-la automaticamente.
    'natureza_operacao_id' => null,

    // Identificador da loja no Bling responsável pela emissão da NF (opcional).
    'loja_id' => null,
    'loja_numero' => null,

    // Modelo e série usados na referência do documento vinculado à venda.
    'documento_modelo' => '55',
    'documento_serie' => '1',

    // Define se o bloco documentoReferenciado deve ser enviado ao Bling.
    'incluir_documento_referenciado' => true,

    // Número manual para a NF. Mantenha null para deixar o Bling controlar a numeração.
    'numero_manual' => null,

    // Observações padrão a serem anexadas à NF emitida automaticamente.
    'observacoes_padrao' => 'Nota fiscal gerada automaticamente via PDV Carmania.',
];
