// Estrutura dos contratos (contracts/), do lado Go. É o espelho de
// central/tests/Unit/ContratosTest.php: o mesmo conjunto de schemas e de fixtures é lido pelos dois.
//
// NÃO valida payload contra os schemas: isso exige uma biblioteca de JSON Schema, que é decisão do
// dono (ADR-014, proposta). Confere o que dá para conferir sem ela, e que quebra feio quando alguém
// mexe: JSON malformado, $ref para definição que não existe, fixture fora do manifesto e fixture
// inválida sem dizer a regra que viola.
package contratos

import (
	"encoding/json"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

// raiz dos contratos, a partir de agents/probe/internal/contratos.
const raiz = "../../../../contracts"

type fixture struct {
	File   string `json:"file"`
	Schema string `json:"schema"`
	Valid  bool   `json:"valid"`
	Regra  string `json:"regra"`
}

func lerJSON(t *testing.T, caminho string) map[string]any {
	t.Helper()
	b, err := os.ReadFile(caminho)
	if err != nil {
		t.Fatalf("lendo %s: %v", caminho, err)
	}
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("JSON malformado em %s: %v", caminho, err)
	}
	return m
}

func manifesto(t *testing.T) []fixture {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(raiz, "fixtures", "manifest.json"))
	if err != nil {
		t.Fatalf("lendo o manifesto: %v", err)
	}
	var m struct {
		Fixtures []fixture `json:"fixtures"`
	}
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("manifesto malformado: %v", err)
	}
	return m.Fixtures
}

func TestSchemasSaoJSONComIDEDialeto2020(t *testing.T) {
	arquivos, _ := filepath.Glob(filepath.Join(raiz, "schemas", "*.schema.json"))
	if len(arquivos) == 0 {
		t.Fatal("nenhum schema encontrado em contracts/schemas")
	}
	for _, a := range arquivos {
		doc := lerJSON(t, a)
		if doc["$schema"] != "https://json-schema.org/draft/2020-12/schema" {
			t.Errorf("%s: $schema = %v", filepath.Base(a), doc["$schema"])
		}
		id, _ := doc["$id"].(string)
		if !strings.HasPrefix(id, "https://b3mon.example/contracts/schemas/") {
			t.Errorf("%s: $id = %q", filepath.Base(a), id)
		}
	}
}

// colherRefs devolve todo valor de "$ref" na árvore.
func colherRefs(no any, saida *[]string) {
	switch v := no.(type) {
	case map[string]any:
		for k, x := range v {
			if s, ok := x.(string); ok && k == "$ref" {
				*saida = append(*saida, s)
			} else {
				colherRefs(x, saida)
			}
		}
	case []any:
		for _, x := range v {
			colherRefs(x, saida)
		}
	}
}

func TestTodoRefEntreSchemasResolveParaUmaDefinicaoQueExiste(t *testing.T) {
	docs := map[string]map[string]any{}
	arquivos, _ := filepath.Glob(filepath.Join(raiz, "schemas", "*.schema.json"))
	for _, a := range arquivos {
		docs[filepath.Base(a)] = lerJSON(t, a)
	}

	for nome, doc := range docs {
		var refs []string
		colherRefs(doc, &refs)
		for _, ref := range refs {
			arquivo, ponteiro, _ := strings.Cut(ref, "#")
			alvo := arquivo
			if alvo == "" {
				alvo = nome
			}
			no, ok := docs[alvo]
			if !ok {
				t.Errorf("%s → %s: o arquivo %s não existe", nome, ref, alvo)
				continue
			}
			var cursor any = no
			for _, parte := range strings.Split(strings.Trim(ponteiro, "/"), "/") {
				if parte == "" {
					continue
				}
				m, ok := cursor.(map[string]any)
				if !ok {
					t.Errorf("%s → %s: %q não é um objeto", nome, ref, parte)
					break
				}
				if cursor, ok = m[parte]; !ok {
					t.Errorf("%s → %s: a definição %q não existe", nome, ref, parte)
					break
				}
			}
		}
	}
}

func TestManifestoEFixturesEmCorrespondenciaUmParaUm(t *testing.T) {
	listadas := []string{}
	vistos := map[string]bool{}
	for _, f := range manifesto(t) {
		if vistos[f.File] {
			t.Errorf("%s aparece duas vezes no manifesto", f.File)
		}
		vistos[f.File] = true
		listadas = append(listadas, f.File)
	}

	base := filepath.Join(raiz, "fixtures")
	emDisco := []string{}
	err := filepath.WalkDir(base, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(base, p)
		if !d.IsDir() && strings.HasSuffix(rel, ".json") && rel != "manifest.json" {
			emDisco = append(emDisco, filepath.ToSlash(rel))
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}

	sort.Strings(listadas)
	sort.Strings(emDisco)
	if strings.Join(listadas, "\n") != strings.Join(emDisco, "\n") {
		t.Fatalf("manifesto e disco divergem.\nmanifesto: %v\ndisco: %v", listadas, emDisco)
	}
}

func TestTodaFixtureEJSONDeUmSchemaQueExisteEAInvalidaDizARegra(t *testing.T) {
	invalidas := 0
	for _, f := range manifesto(t) {
		lerJSON(t, filepath.Join(raiz, "fixtures", f.File))
		if _, err := os.Stat(filepath.Join(raiz, "schemas", f.Schema)); err != nil {
			t.Errorf("%s: o schema %s não existe", f.File, f.Schema)
		}
		// O nome do arquivo e o campo `valid` não podem discordar.
		if comecaValido := strings.HasPrefix(filepath.Base(f.File), "valido"); comecaValido != f.Valid {
			t.Errorf("%s: o nome diz valido=%v e o manifesto diz valid=%v", f.File, comecaValido, f.Valid)
		}
		if !f.Valid {
			invalidas++
			if strings.TrimSpace(f.Regra) == "" {
				t.Errorf("%s é inválida e não diz a regra que viola", f.File)
			}
		}
	}
	if invalidas == 0 {
		t.Error("o manifesto não tem nenhuma fixture inválida")
	}
}
