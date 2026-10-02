// Package relogio isola a leitura do tempo atrás de uma interface.
//
// A agenda da sonda é feita de slots absolutos (schedule_epoch + n × intervalo; 14 §2) e o
// frescor de um resultado depende de "agora". Um código que chama time.Now() direto não se
// testa: nem o atraso, nem o salto de relógio, nem a sonda parada por horas. Com o Relogio
// injetado, o teste avança o tempo à mão e a decisão fica determinística.
package relogio

import (
	"sync"
	"time"
)

// Relogio devolve o instante atual. Sempre em UTC: persistência e contratos são UTC (14 §6).
type Relogio interface {
	Agora() time.Time
}

// Sistema é o relógio real da máquina.
type Sistema struct{}

// Agora devolve time.Now() em UTC.
func (Sistema) Agora() time.Time { return time.Now().UTC() }

// Falso é um relógio controlado pelo teste. Seguro para uso concorrente.
type Falso struct {
	mu    sync.Mutex
	agora time.Time
}

// NovoFalso cria um relógio parado em t, convertido para UTC.
func NovoFalso(t time.Time) *Falso { return &Falso{agora: t.UTC()} }

// Agora devolve o instante em que o relógio está parado.
func (f *Falso) Agora() time.Time {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.agora
}

// Avancar move o relógio para a frente por d. Uma duração negativa é um salto para trás, que é
// um caso real (ajuste de NTP) e que o código de agenda tem de aguentar; por isso é permitida.
func (f *Falso) Avancar(d time.Duration) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.agora = f.agora.Add(d)
}
