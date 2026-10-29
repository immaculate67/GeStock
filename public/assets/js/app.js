const menuToggle = document.querySelector('.menu-toggle');
const sidebar = document.querySelector('#sidebar');

if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
        const isOpen = sidebar.classList.toggle('is-open');
        menuToggle.setAttribute('aria-expanded', String(isOpen));
        menuToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
            sidebar.classList.remove('is-open');
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Open navigation menu');
            menuToggle.focus();
        }

        const saleLines = document.querySelector('.sale-lines');
        const addSaleLine = document.querySelector('.add-sale-line');
        if (saleLines && addSaleLine) {
            let products = [];
            try {
                products = JSON.parse(saleLines.dataset.products || '[]');
            } catch (error) {
                console.error('Não foi possível carregar os produtos da venda.', error);
            }

            const formatCurrency = (cents) => new Intl.NumberFormat('pt-PT', {
                style: 'currency',
                currency: 'EUR',
            }).format(cents / 100);

            const updateTotal = () => {
                let total = 0;
                saleLines.querySelectorAll('.sale-line').forEach((row) => {
                    const product = products.find((item) => String(item.id) === row.querySelector('[name="product_id[]"]').value);
                    const quantity = Number.parseInt(row.querySelector('[name="quantity[]"]').value, 10) || 0;
                    const unitPrice = row.querySelector('[data-unit-price]');
                    const lineTotal = row.querySelector('[data-line-total]');
                    if (product) {
                        if (unitPrice) unitPrice.textContent = `Preço unitário: ${product.price}`;
                        if (lineTotal) lineTotal.textContent = `Subtotal: ${formatCurrency(product.cents * quantity)}`;
                        if (quantity > 0) total += product.cents * quantity;
                    } else {
                        if (unitPrice) unitPrice.textContent = 'Preço unitário: —';
                        if (lineTotal) lineTotal.textContent = `Subtotal: ${formatCurrency(0)}`;
                    }
                });
                const totalElement = document.querySelector('[data-sale-total]');
                if (totalElement) totalElement.textContent = formatCurrency(total);
            };

            const createLine = () => {
                const row = document.createElement('div');
                row.className = 'sale-line';
                const productField = document.createElement('div');
                productField.className = 'field';
                const label = document.createElement('label');
                label.textContent = 'Produto';
                const select = document.createElement('select');
                select.name = 'product_id[]';
                select.required = true;
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Selecionar produto';
                select.append(placeholder);
                products.forEach((product) => {
                    const option = document.createElement('option');
                    option.value = String(product.id);
                    option.textContent = `${product.name} · ${product.sku} · ${product.stock} un. · ${product.price}`;
                    select.append(option);
                });
                label.htmlFor = `sale-product-${saleLines.children.length}`;
                select.id = label.htmlFor;
                productField.append(label, select);
                const quantityField = document.createElement('div');
                quantityField.className = 'field';
                const quantityLabel = document.createElement('label');
                quantityLabel.textContent = 'Quantidade';
                quantityLabel.htmlFor = `sale-quantity-${saleLines.children.length}`;
                const quantityInput = document.createElement('input');
                quantityInput.id = quantityLabel.htmlFor;
                quantityInput.name = 'quantity[]';
                quantityInput.type = 'number';
                quantityInput.min = '1';
                quantityInput.step = '1';
                quantityInput.value = '1';
                quantityInput.required = true;
                quantityField.append(quantityLabel, quantityInput);
                const priceSummary = document.createElement('div');
                priceSummary.className = 'sale-line-meta';
                const unitPrice = document.createElement('span');
                unitPrice.dataset.unitPrice = '';
                unitPrice.textContent = 'Preço unitário: —';
                const lineTotal = document.createElement('strong');
                lineTotal.dataset.lineTotal = '';
                lineTotal.textContent = `Subtotal: ${formatCurrency(0)}`;
                priceSummary.append(unitPrice, lineTotal);
                const remove = document.createElement('button');
                remove.className = 'button button--secondary remove-sale-line';
                remove.type = 'button';
                remove.textContent = 'Remover';
                row.append(productField, quantityField, priceSummary, remove);
                return row;
            };

            addSaleLine.addEventListener('click', () => {
                saleLines.append(createLine());
                updateTotal();
            });
            saleLines.addEventListener('input', updateTotal);
            saleLines.addEventListener('change', updateTotal);
            saleLines.addEventListener('click', (event) => {
                if (event.target instanceof Element && event.target.closest('.remove-sale-line')) {
                    event.target.closest('.sale-line')?.remove();
                    updateTotal();
                }
            });
            addSaleLine.click();
        }
    });
}
