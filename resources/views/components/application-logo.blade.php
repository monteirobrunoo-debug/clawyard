{{-- 2026-05-18: substituído o Laravel logo default pelo ClawYard SVG.
     Pedido directo do operador: "muda este logo para o da ClawYard".
     O componente continua a aceitar $attributes (class, etc) para
     manter compatibilidade com /resources/views/layouts/navigation.blade.php
     e auth scaffold do Breeze. --}}
<img src="{{ asset('images/clawyard-logo.svg') }}"
     alt="ClawYard"
     {{ $attributes->merge(['style' => 'height:32px;width:auto;filter:drop-shadow(0 0 4px rgba(118,185,0,0.25));']) }}>
