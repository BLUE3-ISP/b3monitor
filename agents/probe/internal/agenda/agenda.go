// Package agenda faz a aritmética dos slots (04 §3; 14 §2).
//
// Um slot é `epoch + n × intervalo`, absoluto: ele integra a chave natural da medição e NÃO se desloca com o jitter nem com
// o atraso. O jitter é um offset determinístico somado ao instante em que o slot começa a rodar; o horário real fica no
// `started_at`, separado. Perder um slot não autoriza executar os perdidos em rajada (04 §3): esta lógica só diz onde cada
// slot está em relação a "agora"; quem decide registrá-lo como perdido é o coordenador.
package agenda

import (
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
)

// Situacao de um slot em relação ao instante atual.
type Situacao int

const (
	// Futuro: ainda não chegou a hora de começar.
	Futuro Situacao = iota
	// Devido: está dentro da janela de início (começo até começo + tolerância).
	Devido
	// Perdido: a janela de início já passou.
	Perdido
)

// Slot é o instante do slot `n`: a identidade do resultado (`scheduled_at`).
func Slot(c bundle.Cronograma, n int64) time.Time {
	return c.Epoch.Time.Add(time.Duration(n) * time.Duration(c.IntervaloS) * time.Second).UTC()
}

// Inicio é quando o slot `n` começa a rodar: o slot mais o jitter determinístico.
func Inicio(c bundle.Cronograma, n int64) time.Time {
	return Slot(c, n).Add(time.Duration(c.JitterOffsetMS) * time.Millisecond)
}

// Classificar diz onde o slot `n` está em `agora`.
func Classificar(c bundle.Cronograma, n int64, agora time.Time) Situacao {
	inicio := Inicio(c, n)
	switch {
	case agora.Before(inicio):
		return Futuro
	case agora.After(inicio.Add(time.Duration(c.ToleranciaInicio) * time.Millisecond)):
		return Perdido
	default:
		return Devido
	}
}

// PrimeiroNaoPerdido é o menor slot (>= 0) cuja janela de início ainda não fechou em `agora`. Todos os anteriores a ele,
// se não foram executados, são perdidos.
func PrimeiroNaoPerdido(c bundle.Cronograma, agora time.Time) int64 {
	limite := agora.Add(-time.Duration(c.JitterOffsetMS)*time.Millisecond - time.Duration(c.ToleranciaInicio)*time.Millisecond)
	passo := time.Duration(c.IntervaloS) * time.Second
	d := limite.Sub(c.Epoch.Time)
	if d <= 0 {
		return 0
	}
	n := int64(d / passo)
	if d%passo != 0 {
		n++
	}
	// `Inicio(n)+tol >= agora` é a condição; `n` acima é o primeiro com Slot(n) >= limite.
	return n
}

// Atual é o maior slot cujo começo já chegou (Inicio <= agora), ou -1 se nenhum chegou.
func Atual(c bundle.Cronograma, agora time.Time) int64 {
	d := agora.Add(-time.Duration(c.JitterOffsetMS) * time.Millisecond).Sub(c.Epoch.Time)
	if d < 0 {
		return -1
	}
	return int64(d / (time.Duration(c.IntervaloS) * time.Second))
}
