package relogio

import (
	"sync"
	"testing"
	"time"
)

func TestFalsoComeçaOndeFoiPostoEmUTC(t *testing.T) {
	brasilia := time.FixedZone("BRT", -3*3600)
	f := NovoFalso(time.Date(2026, 10, 2, 9, 0, 0, 0, brasilia))

	got := f.Agora()
	want := time.Date(2026, 10, 2, 12, 0, 0, 0, time.UTC)
	if !got.Equal(want) {
		t.Fatalf("Agora() = %v, quero %v", got, want)
	}
	if got.Location() != time.UTC {
		t.Fatalf("o relógio deve devolver UTC, devolveu %v", got.Location())
	}
}

func TestFalsoAvancaEVoltaNoTempo(t *testing.T) {
	inicio := time.Date(2026, 10, 2, 12, 0, 0, 0, time.UTC)
	f := NovoFalso(inicio)

	f.Avancar(90 * time.Second)
	if got := f.Agora(); !got.Equal(inicio.Add(90 * time.Second)) {
		t.Fatalf("depois de avançar 90 s: %v", got)
	}

	// Salto para trás: um ajuste de NTP real. O relógio tem de aceitar e refletir.
	f.Avancar(-2 * time.Minute)
	if got := f.Agora(); !got.Equal(inicio.Add(-30 * time.Second)) {
		t.Fatalf("depois do salto para trás: %v", got)
	}
}

func TestSistemaDevolveUTCPerto_DeAgora(t *testing.T) {
	antes := time.Now()
	got := Sistema{}.Agora()
	depois := time.Now()

	if got.Location() != time.UTC {
		t.Fatalf("o relógio do sistema deve devolver UTC, devolveu %v", got.Location())
	}
	if got.Before(antes.Add(-time.Second)) || got.After(depois.Add(time.Second)) {
		t.Fatalf("Agora() = %v fora da janela [%v, %v]", got, antes, depois)
	}
}

// Roda com -race: o Falso é compartilhado entre a agenda e o teste.
func TestFalsoAguentaUsoConcorrente(t *testing.T) {
	f := NovoFalso(time.Date(2026, 10, 2, 12, 0, 0, 0, time.UTC))
	var wg sync.WaitGroup
	for i := 0; i < 50; i++ {
		wg.Add(2)
		go func() { defer wg.Done(); f.Avancar(time.Second) }()
		go func() { defer wg.Done(); _ = f.Agora() }()
	}
	wg.Wait()

	want := time.Date(2026, 10, 2, 12, 0, 50, 0, time.UTC)
	if got := f.Agora(); !got.Equal(want) {
		t.Fatalf("depois de 50 avanços de 1 s: %v, quero %v", got, want)
	}
}
