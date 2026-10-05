package agenda

import (
	"testing"
	"time"

	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/bundle"
	"github.com/BLUE3-ISP/b3monitor/agents/probe/internal/resultado"
)

var epoch = time.Date(2026, 9, 28, 14, 0, 0, 0, time.UTC)

func cron() bundle.Cronograma {
	return bundle.Cronograma{Epoch: resultado.Em(epoch), IntervaloS: 30, JitterOffsetMS: 2000, ToleranciaInicio: 6000, TimeoutMS: 5000}
}

func TestSlotEAbsolutoEIndependeDoJitter(t *testing.T) {
	c := cron()
	if got := Slot(c, 0); !got.Equal(epoch) {
		t.Fatalf("slot 0 = %v", got)
	}
	if got := Slot(c, 4); !got.Equal(epoch.Add(120 * time.Second)) {
		t.Fatalf("slot 4 = %v", got)
	}
	// O jitter muda quando o slot COMEÇA, e não qual é o slot.
	if got := Inicio(c, 4); !got.Equal(epoch.Add(122 * time.Second)) {
		t.Fatalf("início do slot 4 = %v", got)
	}
	c.JitterOffsetMS = 0
	if got := Slot(c, 4); !got.Equal(epoch.Add(120 * time.Second)) {
		t.Fatalf("o jitter deslocou a identidade do slot: %v", got)
	}
}

func TestClassificarFuturoDevidoPerdido(t *testing.T) {
	c := cron()
	inicio := Inicio(c, 2) // epoch + 62 s
	casos := []struct {
		quando time.Time
		quer   Situacao
	}{
		{inicio.Add(-1 * time.Millisecond), Futuro},
		{inicio, Devido},
		{inicio.Add(6 * time.Second), Devido}, // a tolerância é inclusiva
		{inicio.Add(6*time.Second + time.Millisecond), Perdido},
	}
	for _, k := range casos {
		if got := Classificar(c, 2, k.quando); got != k.quer {
			t.Errorf("em %v: %v, esperava %v", k.quando, got, k.quer)
		}
	}
}

func TestPrimeiroNaoPerdidoEAtual(t *testing.T) {
	c := cron()
	// Em epoch+100 s: slots 0..3 começaram (0, 32, 62, 92 com jitter), mas só os que ainda têm janela contam.
	agora := epoch.Add(100 * time.Second)
	if got := Atual(c, agora); got != 3 {
		t.Fatalf("atual = %d, esperava 3", got)
	}
	n := PrimeiroNaoPerdido(c, agora)
	for i := int64(0); i < n; i++ {
		if Classificar(c, i, agora) != Perdido {
			t.Fatalf("slot %d antes do primeiro-não-perdido (%d) devia estar perdido", i, n)
		}
	}
	if Classificar(c, n, agora) == Perdido {
		t.Fatalf("o slot %d devia ter janela aberta", n)
	}
}

func TestAntesDoEpochNaoHaSlot(t *testing.T) {
	c := cron()
	if got := Atual(c, epoch.Add(-time.Hour)); got != -1 {
		t.Fatalf("antes do epoch: %d", got)
	}
	if got := PrimeiroNaoPerdido(c, epoch.Add(-time.Hour)); got != 0 {
		t.Fatalf("antes do epoch o primeiro é o 0: %d", got)
	}
}

// Propriedade: para qualquer instante, `PrimeiroNaoPerdido` é exatamente a fronteira entre perdido e não perdido.
func TestFronteiraDoPerdidoEExata(t *testing.T) {
	c := cron()
	for s := 0; s < 600; s += 7 {
		agora := epoch.Add(time.Duration(s)*time.Second + 123*time.Millisecond)
		n := PrimeiroNaoPerdido(c, agora)
		if n > 0 && Classificar(c, n-1, agora) != Perdido {
			t.Fatalf("em +%ds: o slot %d devia estar perdido", s, n-1)
		}
		if Classificar(c, n, agora) == Perdido {
			t.Fatalf("em +%ds: o slot %d não devia estar perdido", s, n)
		}
	}
}

// O slot 3 só COMEÇA em +92 s (jitter de 2 s): em +91 s o atual ainda é o 2.
func TestAtualRespeitaOJitter(t *testing.T) {
	c := cron()
	if got := Atual(c, epoch.Add(91*time.Second)); got != 2 {
		t.Fatalf("em +91 s o atual é o 2, veio %d", got)
	}
	if got := Atual(c, epoch.Add(92*time.Second)); got != 3 {
		t.Fatalf("em +92 s o atual é o 3, veio %d", got)
	}
}
